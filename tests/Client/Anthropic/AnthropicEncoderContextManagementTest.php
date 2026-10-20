<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Config\ClearToolUses;
use Soukicz\Llm\Config\ContextManagement;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class AnthropicEncoderContextManagementTest extends TestCase {
    private function request(?ContextManagement $contextManagement): LLMRequest {
        return new LLMRequest(
            model: new AnthropicClaude55Opus(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            contextManagement: $contextManagement,
        );
    }

    public function testContractExampleEncodesTriggerAndKeep(): void {
        $request = $this->request(new ContextManagement(clearToolUses: new ClearToolUses(triggerInputTokens: 60000, keepToolUses: 3, clearToolInputs: false, excludeTools: [])));
        $encoder = new AnthropicEncoder();

        $this->assertSame(['edits' => [[
            'type' => 'clear_tool_uses_20250919',
            'trigger' => ['type' => 'input_tokens', 'value' => 60000],
            'keep' => ['type' => 'tool_uses', 'value' => 3],
        ]]], $encoder->encodeRequest($request)['context_management']);
        $this->assertSame(['context-management-2025-06-27'], $encoder->getRequestBetas($request));
    }

    public function testEveryField(): void {
        $encoded = (new AnthropicEncoder())->encodeRequest($this->request(new ContextManagement(new ClearToolUses(
            triggerInputTokens: 30000,
            keepToolUses: 2,
            clearToolInputs: true,
            excludeTools: ['str_replace_based_edit_tool'],
            clearAtLeastInputTokens: 5000,
        ))));

        $this->assertSame([
            'type' => 'clear_tool_uses_20250919',
            'trigger' => ['type' => 'input_tokens', 'value' => 30000],
            'keep' => ['type' => 'tool_uses', 'value' => 2],
            'clear_at_least' => ['type' => 'input_tokens', 'value' => 5000],
            'exclude_tools' => ['str_replace_based_edit_tool'],
            'clear_tool_inputs' => true,
        ], $encoded['context_management']['edits'][0]);
    }

    public function testDefaultsOnlySendTheType(): void {
        $encoded = (new AnthropicEncoder())->encodeRequest($this->request(new ContextManagement(new ClearToolUses())));

        $this->assertSame(['edits' => [['type' => 'clear_tool_uses_20250919']]], $encoded['context_management']);
    }

    public function testNothingWithoutEdits(): void {
        $encoder = new AnthropicEncoder();

        foreach ([null, new ContextManagement()] as $contextManagement) {
            $request = $this->request($contextManagement);
            $this->assertArrayNotHasKey('context_management', $encoder->encodeRequest($request));
            $this->assertSame([], $encoder->getRequestBetas($request));
        }
    }
}
