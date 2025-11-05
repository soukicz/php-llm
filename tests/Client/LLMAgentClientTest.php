<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tool\CallbackToolDefinition;

class LLMAgentClientTest extends TestCase {
    /**
     * Test that a single tool use is processed correctly
     */
    public function testSingleToolUse(): void {
        // Create a simple calculator tool for testing
        $calculatorTool = new CallbackToolDefinition(
            'calculator',
            'Basic calculator for math operations',
            [
                'type' => 'object',
                'properties' => [
                    'expression' => [
                        'type' => 'string',
                        'description' => 'Math expression to evaluate',
                    ],
                ],
                'required' => ['expression'],
            ],
            function (array $input): PromiseInterface {
                // Simple calculator that just returns the input and "4" as the result
                return Create::promiseFor(LLMMessageContents::fromArrayData(['result' => 4]));
            }
        );

        // Create a request with the calculator tool
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is 2+2?'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            tools: [$calculatorTool]
        );

        // Create a chain of responses
        $response1 = $this->createToolUseResponse($request, 'tool-123', 'calculator', ['expression' => '2+2']);

        $request2 = $response1->getRequest()->withMessage(
            LLMMessage::createFromUser(new LLMMessageContents([new LLMMessageToolResult('tool-123', LLMMessageContents::fromArrayData(['result' => 4]))]))
        );

        $response2 = $this->createFinalResponse($request2, 'The answer is 4');

        // Create a mock LLM client that returns these responses in sequence
        $mockClient = $this->createMockLLMClient([$response1, $response2]);

        // Create the chain client and run the request
        $agentClient = new LLMAgentClient();
        $finalResponse = $agentClient->run($mockClient, $request);

        // Verify the final response
        $this->assertEquals(StopReason::FINISHED, $finalResponse->getStopReason());
        $this->assertEquals('The answer is 4', $finalResponse->getLastText());
    }

    /**
     * Test multiple nested tool use requests are processed recursively
     */
    public function testMultiTurnToolUse(): void {
        // Create a calculator tool
        $calculatorTool = new CallbackToolDefinition(
            'calculator',
            'Basic calculator for math operations',
            [
                'type' => 'object',
                'properties' => [
                    'expression' => [
                        'type' => 'string',
                        'description' => 'Math expression to evaluate',
                    ],
                ],
                'required' => ['expression'],
            ],
            function (array $input): PromiseInterface {
                $result = $input['expression'] === '2+2' ? 4 : 7;

                return Create::promiseFor(LLMMessageContents::fromArrayData(['result' => $result]));
            }
        );

        // Create a weather tool
        $weatherTool = new CallbackToolDefinition(
            'weather',
            'Get weather information',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'City name',
                    ],
                ],
                'required' => ['location'],
            ],
            function (array $input): PromiseInterface {
                return Create::promiseFor(LLMMessageContents::fromArrayData([
                    'temperature' => 72,
                    'condition' => 'sunny',
                    'location' => $input['location'],
                ]));
            }
        );

        // Create a request with both tools
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is 2+2 and then add 3? Also what\'s the weather in New York?'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            tools: [$calculatorTool, $weatherTool]
        );

        // We need to create a chain of responses that build on each other
        // First, create the initial response with tool use
        $response1 = $this->createToolUseResponse($request, 'tool-123', 'calculator', ['expression' => '2+2']);

        // Create a request that includes the tool result from the first request
        $request2 = $response1->getRequest()->withMessage(
            LLMMessage::createFromUser(new LLMMessageContents([new LLMMessageToolResult('tool-123', LLMMessageContents::fromArrayData(['result' => 4]))]))
        );

        // Create second response with another tool use
        $response2 = $this->createToolUseResponse($request2, 'tool-456', 'calculator', ['expression' => '4+3']);

        // Create a request that includes the tool result from the second request
        $request3 = $response2->getRequest()->withMessage(
            LLMMessage::createFromUser(new LLMMessageContents([new LLMMessageToolResult('tool-456', LLMMessageContents::fromArrayData(['result' => 7]))]))
        );

        // Create third response with third tool use
        $response3 = $this->createToolUseResponse($request3, 'tool-789', 'weather', ['location' => 'New York']);

        // Create a request that includes the tool result from the third request
        $request4 = $response3->getRequest()->withMessage(
            LLMMessage::createFromUser(new LLMMessageContents([
                new LLMMessageToolResult('tool-789', LLMMessageContents::fromArrayData([
                    'temperature' => 72,
                    'condition' => 'sunny',
                    'location' => 'New York',
                ])),
            ]))
        );

        // Create final response
        $response4 = $this->createFinalResponse(
            $request4,
            'The answer to 2+2 is 4, and 4+3 is 7. The weather in New York is sunny with a temperature of 72 degrees.'
        );

        // Create the mock client with the chain of responses
        $mockClient = $this->createMockLLMClient([
            $response1,
            $response2,
            $response3,
            $response4,
        ]);

        // Create the chain client and run the request
        $agentClient = new LLMAgentClient();
        $finalResponse = $agentClient->run($mockClient, $request);

        // Verify the final response
        $this->assertEquals(StopReason::FINISHED, $finalResponse->getStopReason());
        $this->assertEquals(
            'The answer to 2+2 is 4, and 4+3 is 7. The weather in New York is sunny with a temperature of 72 degrees.',
            $finalResponse->getLastText()
        );

        // Verify the conversation history contains all messages
        $allMessages = $finalResponse->getConversation()->getMessages();

        // The response should have all the message history:
        // 1. Initial user query
        // 2. First assistant response with tool use (calculator 2+2)
        // 3. User message with tool result for first calculator
        // 4. Second assistant response with tool use (calculator 4+3)
        // 5. User message with tool result for second calculator
        // 6. Third assistant response with tool use (weather)
        // 7. User message with tool result for weather
        // 8. Final assistant response with answer
        $this->assertCount(8, $allMessages);
    }


    /**
     * Create a mock LLM client that returns predefined responses
     *
     * @param array<LLMResponse> $responses
     */
    private function createMockLLMClient(array $responses): MockObject&LLMClient {
        $mockClient = $this->createMock(LLMClient::class);

        $responseQueue = $responses;

        $mockClient->method('sendRequestAsync')
            ->willReturnCallback(function () use (&$responseQueue) {
                if (empty($responseQueue)) {
                    throw new RuntimeException('No more responses in queue');
                }

                $response = array_shift($responseQueue);

                return Create::promiseFor($response);
            });

        return $mockClient;
    }

    /**
     * Create a response with tool use
     */
    private function createToolUseResponse(
        LLMRequest $request,
        string     $toolId,
        string     $toolName,
        array      $toolInput
    ): LLMResponse {
        $updatedConversation = $request->getConversation()->withMessage(
            LLMMessage::createFromAssistant(new LLMMessageContents([
                new LLMMessageToolUse($toolId, $toolName, $toolInput),
            ]))
        );

        $updatedRequest = new LLMRequest(
            model: $request->getModel(),
            conversation: $updatedConversation,
            tools: $request->getTools()
        );

        return new LLMResponse(
            $updatedRequest,
            StopReason::TOOL_USE,
            100, // input tokens
            50,  // output tokens
            4000, // max tokens
            0.001, // input price
            0.002, // output price
            500 // time in ms
        );
    }

    /**
     * Create a final response with text content
     */
    private function createFinalResponse(LLMRequest $request, string $text): LLMResponse {
        $updatedConversation = $request->getConversation()->withMessage(
            LLMMessage::createFromAssistantString($text)
        );

        $updatedRequest = new LLMRequest(
            model: $request->getModel(),
            conversation: $updatedConversation,
            tools: $request->getTools()
        );

        return new LLMResponse(
            $updatedRequest,
            StopReason::FINISHED,
            100, // input tokens
            50,  // output tokens
            4000, // max tokens
            0.001, // input price
            0.002, // output price
            500 // time in ms
        );
    }



}
