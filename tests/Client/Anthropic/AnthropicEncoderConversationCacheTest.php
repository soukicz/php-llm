<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Config\CacheTtl;
use Soukicz\Llm\Config\ConversationCacheConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class AnthropicEncoderConversationCacheTest extends TestCase {
    private AnthropicEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new AnthropicEncoder();
    }

    /**
     * Conversation shaped like an agent tool loop: cached document prompt, then
     * alternating assistant tool_use / user tool_result turns
     */
    private function createToolLoopConversation(int $turns): LLMConversation {
        $messages = [
            LLMMessage::createFromUser(new LLMMessageContents([
                new LLMMessageText('Extract items from this invoice', true),
            ])),
        ];
        for ($turn = 1; $turn <= $turns; $turn++) {
            $messages[] = LLMMessage::createFromAssistant(new LLMMessageContents([
                new LLMMessageToolUse('tool-' . $turn, 'item_export', ['rows' => 'turn ' . $turn]),
            ]));
            $messages[] = LLMMessage::createFromUser(new LLMMessageContents([
                new LLMMessageToolResult('tool-' . $turn, LLMMessageContents::fromArrayData(['ok' => true])),
            ]));
        }

        return new LLMConversation($messages);
    }

    private function countBreakpoints(array $encodedMessages): int {
        $count = 0;
        foreach ($encodedMessages as $message) {
            foreach ($message['content'] as $content) {
                if (isset($content['cache_control'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function testNoConfigKeepsEncodingUnchanged(): void {
        $request = new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $this->createToolLoopConversation(2),
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Only the explicitly cached document prompt carries a breakpoint
        $this->assertSame(1, $this->countBreakpoints($encoded['messages']));
        $this->assertEquals(['type' => 'ephemeral'], $encoded['messages'][0]['content'][0]['cache_control']);
        $lastMessage = $encoded['messages'][count($encoded['messages']) - 1];
        $this->assertArrayNotHasKey('cache_control', $lastMessage['content'][0]);
    }

    public function testMovingBreakpointsOnLastTwoUserMessages(): void {
        $request = new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $this->createToolLoopConversation(3),
            conversationCacheConfig: new ConversationCacheConfig(),
        );

        $encoded = $this->encoder->encodeRequest($request);
        $messages = $encoded['messages'];

        // Explicit prefix breakpoint is kept
        $this->assertEquals(['type' => 'ephemeral'], $messages[0]['content'][0]['cache_control']);

        // Moving breakpoints on the tool results of the last two turns
        $this->assertEquals(['type' => 'ephemeral'], $messages[6]['content'][0]['cache_control']);
        $this->assertSame('tool_result', $messages[6]['content'][0]['type']);
        $this->assertEquals(['type' => 'ephemeral'], $messages[4]['content'][0]['cache_control']);

        // Older tool results are not marked - breakpoints move instead of accumulating
        $this->assertArrayNotHasKey('cache_control', $messages[2]['content'][0]);
        $this->assertSame(3, $this->countBreakpoints($messages));
    }

    public function testFirstTurnMarksOnlyDocumentPrompt(): void {
        $request = new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $this->createToolLoopConversation(0),
            conversationCacheConfig: new ConversationCacheConfig(),
        );

        $encoded = $this->encoder->encodeRequest($request);

        // The only user message already carries the caller's breakpoint - nothing is added
        $this->assertSame(1, $this->countBreakpoints($encoded['messages']));
    }

    public function testBreakpointCountNeverExceedsLimit(): void {
        // Simulate a growing agent loop and encode the request on every turn
        $conversation = $this->createToolLoopConversation(0);
        for ($turn = 1; $turn <= 8; $turn++) {
            $conversation = $conversation
                ->withMessage(LLMMessage::createFromAssistant(new LLMMessageContents([
                    new LLMMessageToolUse('tool-' . $turn, 'item_export', ['rows' => 'turn ' . $turn]),
                ])))
                ->withMessage(LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageToolResult('tool-' . $turn, LLMMessageContents::fromArrayData(['ok' => true])),
                ])));

            $request = new LLMRequest(
                model: new AnthropicClaude5Sonnet(),
                conversation: $conversation,
                conversationCacheConfig: new ConversationCacheConfig(),
            );

            $encoded = $this->encoder->encodeRequest($request);
            $messages = $encoded['messages'];

            $this->assertLessThanOrEqual(4, $this->countBreakpoints($messages), 'Turn ' . $turn);

            // The newest tool result always carries the moving breakpoint
            $lastMessage = $messages[count($messages) - 1];
            $this->assertArrayHasKey('cache_control', $lastMessage['content'][0], 'Turn ' . $turn);
        }
    }

    public function testExplicitBreakpointsAtLimitDisableMovingBreakpoints(): void {
        $messages = [];
        for ($i = 1; $i <= 4; $i++) {
            $messages[] = LLMMessage::createFromUser(new LLMMessageContents([
                new LLMMessageText('Cached part ' . $i, true),
            ]));
            $messages[] = LLMMessage::createFromAssistant(new LLMMessageContents([
                new LLMMessageText('Answer ' . $i),
            ]));
        }
        $messages[] = LLMMessage::createFromUserString('Follow-up question');

        $request = new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: new LLMConversation($messages),
            conversationCacheConfig: new ConversationCacheConfig(),
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Caller's four explicit breakpoints win - no moving breakpoint is added
        $this->assertSame(4, $this->countBreakpoints($encoded['messages']));
        $lastMessage = $encoded['messages'][count($encoded['messages']) - 1];
        $this->assertArrayNotHasKey('cache_control', $lastMessage['content'][0]);
    }

    /**
     * Anthropic requires longer-TTL cache entries to appear before shorter-TTL ones,
     * so a non-default TTL must apply to every breakpoint in the request - a 5-minute
     * explicit breakpoint followed by 1-hour moving breakpoints would be rejected
     */
    public function testTtlIsAppliedToAllBreakpoints(): void {
        $request = new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $this->createToolLoopConversation(2),
            conversationCacheConfig: new ConversationCacheConfig(ttl: CacheTtl::ONE_HOUR),
        );

        $encoded = $this->encoder->encodeRequest($request);
        $messages = $encoded['messages'];

        $lastMessage = $messages[count($messages) - 1];
        $this->assertEquals(['type' => 'ephemeral', 'ttl' => '1h'], $lastMessage['content'][0]['cache_control']);

        // The explicit caller breakpoint earlier in the conversation gets the same TTL
        $this->assertEquals(['type' => 'ephemeral', 'ttl' => '1h'], $messages[0]['content'][0]['cache_control']);

        foreach ($messages as $message) {
            foreach ($message['content'] as $content) {
                if (isset($content['cache_control'])) {
                    $this->assertSame('1h', $content['cache_control']['ttl'] ?? null);
                }
            }
        }
    }

    /**
     * On the first turn the caller's explicit breakpoint already sits on the last block,
     * so the encoded request must be byte-identical with and without the config - the
     * feature must not change what the first request writes to the provider cache (and
     * must not change its HTTP-cache key)
     */
    public function testFirstTurnEncodingIsByteIdenticalWithAndWithoutConfig(): void {
        $conversation = $this->createToolLoopConversation(0);

        $encodedWithoutConfig = $this->encoder->encodeRequest(new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $conversation,
        ));
        $encodedWithConfig = $this->encoder->encodeRequest(new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $conversation,
            conversationCacheConfig: new ConversationCacheConfig(),
        ));

        $this->assertSame(
            json_encode($encodedWithoutConfig, JSON_THROW_ON_ERROR),
            json_encode($encodedWithConfig, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * The prefix of a later turn must repeat the previous turn's request byte-identically
     * apart from cache_control markers (which the provider strips before prefix hashing) -
     * the moving breakpoints may never mutate or reorder previously sent content
     */
    public function testLaterTurnsKeepPreviousTurnPrefixByteIdentical(): void {
        $encodedTurns = [];
        foreach ([1, 2, 3] as $turns) {
            $encodedTurns[$turns] = $this->encoder->encodeRequest(new LLMRequest(
                model: new AnthropicClaude5Sonnet(),
                conversation: $this->createToolLoopConversation($turns),
                conversationCacheConfig: new ConversationCacheConfig(),
            ));
        }

        $stripCacheControl = static function (array $messages): array {
            foreach ($messages as $messageIndex => $message) {
                foreach ($message['content'] as $contentIndex => $content) {
                    unset($messages[$messageIndex]['content'][$contentIndex]['cache_control']);
                }
            }

            return $messages;
        };

        foreach ([2, 3] as $turns) {
            $previous = $encodedTurns[$turns - 1];
            $current = $encodedTurns[$turns];

            // Everything except the message list is identical
            unset($previous['messages'], $current['messages']);
            $this->assertSame(
                json_encode($previous, JSON_THROW_ON_ERROR),
                json_encode($current, JSON_THROW_ON_ERROR),
                'Turn ' . $turns
            );

            $previousMessages = $stripCacheControl($encodedTurns[$turns - 1]['messages']);
            $currentPrefix = $stripCacheControl(array_slice($encodedTurns[$turns]['messages'], 0, count($previousMessages)));
            $this->assertSame(
                json_encode($previousMessages, JSON_THROW_ON_ERROR),
                json_encode($currentPrefix, JSON_THROW_ON_ERROR),
                'Turn ' . $turns
            );
        }
    }

    public function testConversationEndingWithAssistantMarksUserMessages(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('First question'),
            LLMMessage::createFromAssistant(new LLMMessageContents([
                new LLMMessageText('First answer'),
            ])),
            LLMMessage::createFromUserString('Second question'),
            LLMMessage::createFromAssistant(new LLMMessageContents([
                new LLMMessageReasoning('thinking about it', 'sig123'),
                new LLMMessageText('Second answer'),
            ])),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude5Sonnet(),
            conversation: $conversation,
            conversationCacheConfig: new ConversationCacheConfig(),
        );

        $encoded = $this->encoder->encodeRequest($request);
        $messages = $encoded['messages'];

        // Assistant messages (including thinking blocks) are never marked
        $this->assertArrayNotHasKey('cache_control', $messages[1]['content'][0]);
        $this->assertArrayNotHasKey('cache_control', $messages[3]['content'][0]);
        $this->assertArrayNotHasKey('cache_control', $messages[3]['content'][1]);

        // Both user messages carry the moving breakpoints
        $this->assertArrayHasKey('cache_control', $messages[0]['content'][0]);
        $this->assertArrayHasKey('cache_control', $messages[2]['content'][0]);
    }
}
