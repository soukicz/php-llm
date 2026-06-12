<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Haiku;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\MarkdownFormatter;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class MarkdownFormatterTest extends TestCase {
    private MarkdownFormatter $formatter;

    protected function setUp(): void {
        $this->formatter = new MarkdownFormatter();
    }

    private function createRequest(): LLMRequest {
        return new LLMRequest(
            model: new AnthropicClaude45Haiku(AnthropicClaude45Haiku::VERSION_20251001),
            conversation: new LLMConversation([
                LLMMessage::createFromSystem(LLMMessageContents::fromString('You are a helpful assistant')),
                LLMMessage::createFromUserString('What is 2+2?'),
                LLMMessage::createFromAssistant(new LLMMessageContents([
                    new LLMMessageToolUse('tool-1', 'calculator', ['expression' => '2+2']),
                ])),
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageToolResult('tool-1', LLMMessageContents::fromArrayData(['result' => 4])),
                ])),
                LLMMessage::createFromAssistantString('The answer is 4'),
            ]),
        );
    }

    public function testRequestFormatting(): void {
        $markdown = $this->formatter->responseToMarkdown($this->createRequest());

        $this->assertStringContainsString(' - **Model:** claude-haiku-4-5-20251001', $markdown);
        // Each role gets its own heading (system messages used to render as "## User:")
        $this->assertStringContainsString('## System:', $markdown);
        $this->assertStringContainsString('## User:', $markdown);
        $this->assertStringContainsString('## Assistant:', $markdown);
        $this->assertStringContainsString('You are a helpful assistant', $markdown);
        $this->assertStringContainsString('**Tool use:** calculator (tool-1)', $markdown);
        $this->assertStringContainsString('**Tool result:** tool-1', $markdown);
        $this->assertStringContainsString('The answer is 4', $markdown);
    }

    public function testResponseFormattingIncludesStats(): void {
        $response = new LLMResponse($this->createRequest(), StopReason::FINISHED, 1000, 200, 200, 0.5, 0.25, 1500);

        $markdown = $this->formatter->responseToMarkdown($response);

        $this->assertStringContainsString('##### Total stats', $markdown);
        $this->assertStringContainsString('prompt tokens: 1000', $markdown);
        $this->assertStringContainsString('completion tokens: 200', $markdown);
        $this->assertStringContainsString('price: $0.750', $markdown);
        $this->assertStringContainsString('Finished in 1.500s', $markdown);
    }

    /**
     * Models without configured pricing produce null prices - formatting must not fail
     */
    public function testResponseFormattingWithNullPrices(): void {
        $response = new LLMResponse($this->createRequest(), StopReason::FINISHED, 1000, 200, 200, null, null, 1500);

        $markdown = $this->formatter->responseToMarkdown($response);

        $this->assertStringContainsString('price: $0.000', $markdown);
    }
}
