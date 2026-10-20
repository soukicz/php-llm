<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\Client\Gemini\Model\Gemini38Flash;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Usage\TokenUsage;

class GeminiDecoderUsageTest extends TestCase {
    public function testStepUsageCarriesThoughtTokens(): void {
        $request = new LLMRequest(
            model: new Gemini38Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
        );

        $response = (new GeminiEncoder())->decodeResponse($request, new ModelResponse([
            'candidates' => [['content' => ['parts' => [['text' => 'Hello']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 40, 'thoughtsTokenCount' => 25],
        ], 100));

        $this->assertInstanceOf(LLMResponse::class, $response);
        $this->assertEquals(new TokenUsage(input: 200, output: 40, reasoning: 25), $response->getStepUsage());
    }
}
