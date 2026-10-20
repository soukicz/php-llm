<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageOpaque;
use Soukicz\Llm\Message\LLMMessageRedactedReasoning;

class AnthropicDecoderBlocksTest extends TestCase {
    private const SERVER_TOOL_USE = ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => []];

    private function decode(array $content, string $stopReason, array $extra = []): LLMResponse {
        $request = new LLMRequest(
            model: new AnthropicClaude55Opus(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
        );
        $response = (new AnthropicEncoder())->decodeResponse($request, new ModelResponse([
            'content' => $content,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => $stopReason,
        ] + $extra, 100));
        $this->assertInstanceOf(LLMResponse::class, $response);

        return $response;
    }

    private function encodeWithNextUserMessage(LLMConversation $conversation): array {
        return (new AnthropicEncoder())->encodeRequest(new LLMRequest(
            model: new AnthropicClaude55Opus(),
            conversation: $conversation->withMessage(LLMMessage::createFromUserString('Next')),
        ));
    }

    public function testRefusalIsAStopReasonWithDetails(): void {
        $response = $this->decode([], 'refusal', [
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined.'],
        ]);

        $this->assertSame(StopReason::REFUSAL, $response->getStopReason());
        $this->assertSame('cyber', $response->getStopDetails()['category']);
    }

    public function testPauseTurnIsAStopReason(): void {
        $response = $this->decode([self::SERVER_TOOL_USE], 'pause_turn');

        $this->assertSame(StopReason::PAUSE_TURN, $response->getStopReason());
        $this->assertNull($response->getStopDetails());
    }

    public function testContextWindowExceededIsLength(): void {
        $this->assertSame(StopReason::LENGTH, $this->decode([['type' => 'text', 'text' => 'Partial']], 'model_context_window_exceeded')->getStopReason());
    }

    public function testRedactedThinkingRoundTripsUnchanged(): void {
        $response = $this->decode([['type' => 'redacted_thinking', 'data' => 'EmwKAhgB'], ['type' => 'text', 'text' => 'Done']], 'end_turn');

        $blocks = $response->getConversation()->getLastMessage()->getContents();
        $this->assertInstanceOf(LLMMessageRedactedReasoning::class, $blocks[0]);
        $this->assertSame('EmwKAhgB', $blocks[0]->getData());

        $encoded = $this->encodeWithNextUserMessage($response->getConversation());
        $this->assertSame(['type' => 'redacted_thinking', 'data' => 'EmwKAhgB'], $encoded['messages'][1]['content'][0]);
    }

    public function testUnknownBlocksArePassedThroughUnchanged(): void {
        $result = [
            'type' => 'web_search_tool_result',
            'tool_use_id' => 'srvtoolu_1',
            'content' => [['type' => 'web_search_result', 'url' => 'https://example.com', 'title' => 'Example']],
        ];
        $response = $this->decode([self::SERVER_TOOL_USE, $result, ['type' => 'text', 'text' => 'Found it']], 'end_turn');

        $blocks = $response->getConversation()->getLastMessage()->getContents();
        $this->assertInstanceOf(LLMMessageOpaque::class, $blocks[0]);
        $this->assertSame('server_tool_use', $blocks[0]->getType());
        $this->assertSame('anthropic', $blocks[0]->getProvider());

        $assistant = $this->encodeWithNextUserMessage($response->getConversation())['messages'][1]['content'];
        // an empty tool input goes back as a JSON object, never as a list
        $this->assertSame('{"type":"server_tool_use","id":"srvtoolu_1","name":"web_search","input":{}}', json_encode($assistant[0], JSON_THROW_ON_ERROR));
        $this->assertSame($result, $assistant[1]);
    }

    /**
     * Review focus 4: transcripts are stored as JSON and replayed on the next turn
     */
    public function testUnknownBlocksSurviveJsonPersistence(): void {
        $response = $this->decode([self::SERVER_TOOL_USE, ['type' => 'redacted_thinking', 'data' => 'abc']], 'end_turn');

        $json = json_encode($response->getConversation(), JSON_THROW_ON_ERROR);
        $restored = LLMConversation::fromJson(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        $assistant = $this->encodeWithNextUserMessage($restored)['messages'][1]['content'];
        $this->assertSame('{"type":"server_tool_use","id":"srvtoolu_1","name":"web_search","input":{}}', json_encode($assistant[0], JSON_THROW_ON_ERROR));
        $this->assertSame(['type' => 'redacted_thinking', 'data' => 'abc'], $assistant[1]);
    }

    public function testOpaqueContentOfAnotherProviderIsRejected(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hi'),
            LLMMessage::createFromAssistant(new LLMMessageContents([new LLMMessageOpaque('openai', ['type' => 'reasoning'])])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Opaque content from provider "openai" cannot be sent to Anthropic');

        $this->encodeWithNextUserMessage($conversation);
    }
}
