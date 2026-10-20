<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicModel;
use Soukicz\Llm\Config\CacheTtl;
use Soukicz\Llm\Config\ConversationCacheConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class AnthropicEncoderSystemMessagesTest extends TestCase {
    /**
     * @param LLMMessage[] $messages
     */
    private function encode(AnthropicModel $model, array $messages, ?ConversationCacheConfig $cache = null): array {
        return (new AnthropicEncoder())->encodeRequest(new LLMRequest(
            model: $model,
            conversation: new LLMConversation($messages),
            conversationCacheConfig: $cache,
        ));
    }

    /**
     * @return LLMMessage[]
     */
    private static function turnWithStateNote(): array {
        return [
            LLMMessage::createFromSystemString('You are the template agent.'),
            LLMMessage::createFromUserString('Make a sale e-mail'),
            LLMMessage::createFromAssistantString('Done.'),
            LLMMessage::createFromUserString('Shorter please'),
            LLMMessage::createFromSystemString('Editor state: 3 boxes.'),
        ];
    }

    public function testCachedSystemPromptGetsABreakpoint(): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), [
            LLMMessage::createFromSystemString('You are the template agent.', true),
            LLMMessage::createFromUserString('Hi'),
        ]);

        $this->assertSame([['type' => 'text', 'text' => 'You are the template agent.', 'cache_control' => ['type' => 'ephemeral']]], $encoded['system']);
    }

    public function testUncachedSystemPromptStaysAString(): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), [LLMMessage::createFromSystemString('Plain.'), LLMMessage::createFromUserString('Hi')]);

        $this->assertSame('Plain.', $encoded['system']);
    }

    public function testConversationCacheTtlAppliesToTheSystemBreakpoint(): void {
        $encoded = $this->encode(
            new AnthropicClaude55Opus(),
            [LLMMessage::createFromSystemString('Stable prompt.', true), LLMMessage::createFromUserString('Hi')],
            new ConversationCacheConfig(CacheTtl::ONE_HOUR),
        );

        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $encoded['system'][0]['cache_control']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $encoded['messages'][0]['content'][0]['cache_control']);
    }

    public function testLaterSystemMessageIsSentInPlaceWhereSupported(): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), self::turnWithStateNote());

        $this->assertSame('You are the template agent.', $encoded['system']);
        $this->assertCount(4, $encoded['messages']);
        $this->assertSame(['role' => 'system', 'content' => 'Editor state: 3 boxes.'], $encoded['messages'][3]);
    }

    public function testLaterSystemMessageIsMergedIntoTheUserMessageElsewhere(): void {
        $encoded = $this->encode(new AnthropicClaude5Sonnet(), self::turnWithStateNote());

        $this->assertCount(3, $encoded['messages']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Shorter please'],
            ['type' => 'text', 'text' => 'Editor state: 3 boxes.'],
        ], $encoded['messages'][2]['content']);
    }

    public function testSystemMessageBetweenTwoUserMessagesIsMergedIntoThePreviousOne(): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), [
            LLMMessage::createFromUserString('First'),
            LLMMessage::createFromSystemString('Note.'),
            LLMMessage::createFromUserString('Second'),
            LLMMessage::createFromSystemString('Another note.'),
        ]);

        $this->assertSame('user', $encoded['messages'][0]['role']);
        $this->assertSame([['type' => 'text', 'text' => 'First'], ['type' => 'text', 'text' => 'Note.']], $encoded['messages'][0]['content']);
        $this->assertSame(['role' => 'system', 'content' => 'Another note.'], $encoded['messages'][2]);
    }

    /**
     * Review focus 5: nothing may sit between an assistant tool_use and its tool_result
     */
    public function testSystemNoteAfterToolUseGoesAfterTheToolResults(): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), [
            // a leading system prompt, so the later note is not "the only system message" (which stays top-level)
            LLMMessage::createFromSystemString('You are the shop agent.'),
            LLMMessage::createFromUserString('Find shoes'),
            LLMMessage::createFromAssistant(new LLMMessageContents([new LLMMessageToolUse('t1', 'search', ['q' => 'shoes'])])),
            LLMMessage::createFromSystemString('Stock changed meanwhile.'),
            LLMMessage::createFromUser(new LLMMessageContents([new LLMMessageToolResult('t1', LLMMessageContents::fromString('3 results'))])),
        ]);

        $this->assertCount(3, $encoded['messages']);
        $this->assertSame('assistant', $encoded['messages'][1]['role']);
        $this->assertSame('tool_result', $encoded['messages'][2]['content'][0]['type']);
        $this->assertSame(['type' => 'text', 'text' => 'Stock changed meanwhile.'], $encoded['messages'][2]['content'][1]);
    }

    public function testMovingBreakpointsSkipSystemMessages(): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), self::turnWithStateNote(), new ConversationCacheConfig());

        $this->assertSame(['type' => 'ephemeral'], $encoded['messages'][2]['content'][0]['cache_control']);
        $this->assertSame(['role' => 'system', 'content' => 'Editor state: 3 boxes.'], $encoded['messages'][3]);
    }

    public function testSystemMessageWithoutANeighbouringUserMessageIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A system message after the conversation start needs a user message next to it');

        $this->encode(new AnthropicClaude55Opus(), [
            LLMMessage::createFromSystemString('Base prompt.'),
            LLMMessage::createFromUserString('Hi'),
            LLMMessage::createFromAssistantString('Hello'),
            LLMMessage::createFromSystemString('Note.'),
        ]);
    }
}
