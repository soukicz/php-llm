<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Agent\AgentRunInterruptedException;
use Soukicz\Llm\Agent\AgentRunOptions;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageOpaque;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageRedactedReasoning;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tests\Client\ScriptedLLMClient;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Usage\TokenUsage;

class AgentRunTest extends TestCase {
    private const SCHEMA = ['type' => 'object', 'properties' => ['expression' => ['type' => 'string']], 'required' => ['expression']];

    /**
     * @param CallbackToolDefinition[] $tools
     */
    private function request(array $tools): LLMRequest {
        return new LLMRequest(
            model: new AnthropicClaude55Opus(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('What is 2+2?')]),
            tools: $tools,
        );
    }

    private function calculator(int &$calls, ?\Closure $sideEffect = null): CallbackToolDefinition {
        return new CallbackToolDefinition('calculator', 'Calculator', self::SCHEMA, function (array $input) use (&$calls, $sideEffect): LLMMessageContents {
            $calls++;
            if ($sideEffect !== null) {
                $sideEffect();
            }

            return LLMMessageContents::fromArrayData(['result' => 4]);
        });
    }

    public function testListenerSeesEveryModelCallAndToolCall(): void {
        $calls = 0;
        $failing = new CallbackToolDefinition('lookup', 'Lookup', self::SCHEMA, function (array $input): LLMMessageContents {
            throw new \RuntimeException('offline');
        });
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::reply(new LLMMessageContents([
                new LLMMessageToolUse('t1', 'calculator', ['expression' => '2+2']),
                new LLMMessageToolUse('t2', 'lookup', ['expression' => 'x']),
            ]), StopReason::TOOL_USE),
            ScriptedLLMClient::text('4'),
        );
        $listener = new RecordingAgentListener();

        $response = (new LLMAgentClient())->run($client, $this->request([$this->calculator($calls), $failing]), null, new AgentRunOptions(listener: $listener));

        $this->assertSame('4', $response->getLastText());
        $this->assertSame([
            ['model', 'tool_use', 100],
            ['start', 'calculator', ['expression' => '2+2']],
            ['finish', 'calculator', false],
            ['start', 'lookup', ['expression' => 'x']],
            ['finish', 'lookup', true],
            ['model', 'finished', 100],
        ], $listener->events);
        $this->assertCount(2, $listener->durationsMs);
        foreach ($listener->durationsMs as $durationMs) {
            $this->assertGreaterThanOrEqual(0, $durationMs);
        }
    }

    public function testIterationLimitStopsBeforeTheNextModelCall(): void {
        $calls = 0;
        $client = new ScriptedLLMClient(...ScriptedLLMClient::repeat(5, ScriptedLLMClient::toolUse('t', 'calculator', ['expression' => '2+2'])));

        try {
            (new LLMAgentClient())->run($client, $this->request([$this->calculator($calls)]), null, new AgentRunOptions(maxIterations: 3));
            $this->fail('Expected AgentRunInterruptedException');
        } catch (AgentRunInterruptedException $e) {
            $this->assertSame(AgentRunInterruptedException::REASON_ITERATIONS, $e->getReason());
            $this->assertCount(3, $client->sent);
            $this->assertSame(3, $calls);
            $last = $e->getConversation()->getLastMessage();
            $this->assertTrue($last->isUser());
            $this->assertInstanceOf(LLMMessageToolResult::class, $last->getContents()[0]);
            $this->assertSame(300, $e->getUsage()->input);
        }
    }

    public function testCostLimitStopsAfterTheResponseThatCrossesIt(): void {
        $calls = 0;
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::toolUse('t1', 'calculator', ['expression' => '1'], 0.6),
            ScriptedLLMClient::toolUse('t2', 'calculator', ['expression' => '2'], 0.6),
            ScriptedLLMClient::text('never'),
        );

        try {
            (new LLMAgentClient())->run($client, $this->request([$this->calculator($calls)]), null, new AgentRunOptions(maxCostUsd: 1.0));
            $this->fail('Expected AgentRunInterruptedException');
        } catch (AgentRunInterruptedException $e) {
            $this->assertSame(AgentRunInterruptedException::REASON_COST, $e->getReason());
            $this->assertCount(2, $client->sent);
            $this->assertSame(1, $calls, 'the tools of the response that crossed the limit must not run');
            $this->assertEqualsWithDelta(1.2, $e->getCostUsd(), 1e-9);
            $closing = $e->getConversation()->getLastMessage()->getContents()[0];
            $this->assertSame('t2', $closing->getId());
            $this->assertTrue($closing->getContent()->isError());
            $this->assertSame('ERROR: not run, the agent run was interrupted (cost)', $closing->getContent()->getMessages()[0]->getText());
        }
    }

    public function testPassedDeadlineStopsBeforeAnyModelCall(): void {
        $client = new ScriptedLLMClient(ScriptedLLMClient::text('never'));
        $request = $this->request([]);

        try {
            (new LLMAgentClient())->run($client, $request, null, new AgentRunOptions(deadline: microtime(true) - 1));
            $this->fail('Expected AgentRunInterruptedException');
        } catch (AgentRunInterruptedException $e) {
            $this->assertSame(AgentRunInterruptedException::REASON_DEADLINE, $e->getReason());
            $this->assertCount(0, $client->sent);
            $this->assertSame($request->getConversation(), $e->getConversation());
            $this->assertSame(0.0, $e->getCostUsd());
            $this->assertEquals(new TokenUsage(), $e->getUsage());
        }
    }

    public function testRunAsyncRejectsInsteadOfThrowing(): void {
        $promise = (new LLMAgentClient())->runAsync(new ScriptedLLMClient(), $this->request([]), null, new AgentRunOptions(deadline: microtime(true) - 1));

        $this->assertSame('rejected', $promise->getState());
    }

    /**
     * Review focus 1: the stored conversation must replay as a valid request
     */
    public function testCancelBetweenToolCallsClosesTheOpenToolUses(): void {
        $calls = 0;
        $cancel = false;
        $tool = $this->calculator($calls, function () use (&$cancel): void {
            $cancel = true;
        });
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::reply(new LLMMessageContents([
                new LLMMessageToolUse('t1', 'calculator', ['expression' => '1']),
                new LLMMessageToolUse('t2', 'calculator', ['expression' => '2']),
            ]), StopReason::TOOL_USE),
            ScriptedLLMClient::text('never'),
        );
        $shouldCancel = function () use (&$cancel): bool {
            return $cancel;
        };

        try {
            (new LLMAgentClient())->run($client, $this->request([$tool]), null, new AgentRunOptions(shouldCancel: $shouldCancel));
            $this->fail('Expected AgentRunInterruptedException');
        } catch (AgentRunInterruptedException $e) {
            $this->assertSame(AgentRunInterruptedException::REASON_CANCELLED, $e->getReason());
            $this->assertCount(1, $client->sent);
            $this->assertSame(1, $calls);
            $results = $e->getConversation()->getLastMessage()->getContents();
            $this->assertSame('t1', $results[0]->getId());
            $this->assertFalse($results[0]->getContent()->isError());
            $this->assertSame('t2', $results[1]->getId());
            $this->assertSame('ERROR: not run, the agent run was interrupted (cancelled)', $results[1]->getContent()->getMessages()[0]->getText());

            $encoded = (new AnthropicEncoder())->encodeRequest(new LLMRequest(
                model: new AnthropicClaude55Opus(),
                conversation: $e->getConversation()->withMessage(LLMMessage::createFromUserString('continue')),
            ));
            $toolUseIds = array_column(array_filter($encoded['messages'][1]['content'], static fn(array $b): bool => $b['type'] === 'tool_use'), 'id');
            $toolResultIds = array_column(array_filter($encoded['messages'][2]['content'], static fn(array $b): bool => $b['type'] === 'tool_result'), 'tool_use_id');
            $this->assertSame(['t1', 't2'], $toolUseIds);
            $this->assertSame($toolUseIds, $toolResultIds);
        }
    }

    public function testInterruptedConversationReplaysAfterJsonRoundTrip(): void {
        $calls = 0;
        $cancel = false;
        $tool = $this->calculator($calls, function () use (&$cancel): void {
            $cancel = true;
        });
        $opaque = ['type' => 'web_search_tool_result', 'tool_use_id' => 'srv1', 'content' => [['type' => 'web_search_result', 'url' => 'https://example.com']]];
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::reply(new LLMMessageContents([
                new LLMMessageReasoning('thinking it over', 'sig-1'),
                new LLMMessageRedactedReasoning('opaque-redacted-data'),
                new LLMMessageOpaque('anthropic', ['type' => 'server_tool_use', 'id' => 'srv1', 'name' => 'web_search', 'input' => []]),
                new LLMMessageOpaque('anthropic', $opaque),
                new LLMMessageToolUse('t1', 'calculator', ['expression' => '1']),
                new LLMMessageToolUse('t2', 'calculator', ['expression' => '2']),
            ]), StopReason::TOOL_USE),
            ScriptedLLMClient::text('never'),
        );

        try {
            (new LLMAgentClient())->run($client, $this->request([$tool]), null, new AgentRunOptions(shouldCancel: static function () use (&$cancel): bool {
                return $cancel;
            }));
            $this->fail('Expected AgentRunInterruptedException');
        } catch (AgentRunInterruptedException $e) {
            $this->assertSame(1, $calls);
            $restored = LLMConversation::fromJson(json_decode(json_encode($e->getConversation(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        }

        $encoded = (new AnthropicEncoder())->encodeRequest(new LLMRequest(
            model: new AnthropicClaude55Opus(),
            conversation: $restored->withMessage(LLMMessage::createFromUserString('continue')),
        ));

        $this->assertSame(['user', 'assistant', 'user', 'user'], array_column($encoded['messages'], 'role'));
        $assistant = $encoded['messages'][1]['content'];
        $this->assertSame(['thinking', 'redacted_thinking', 'server_tool_use', 'web_search_tool_result', 'tool_use', 'tool_use'], array_column($assistant, 'type'));
        $this->assertSame('sig-1', $assistant[0]['signature']);
        $this->assertSame('opaque-redacted-data', $assistant[1]['data']);
        $this->assertEquals((object) [], $assistant[2]['input']);
        $this->assertSame($opaque, $assistant[3]);
        $toolUseIds = array_column(array_filter($assistant, static fn(array $b): bool => $b['type'] === 'tool_use'), 'id');
        $results = array_values(array_filter($encoded['messages'][2]['content'], static fn(array $b): bool => $b['type'] === 'tool_result'));
        $this->assertSame(['t1', 't2'], $toolUseIds);
        $this->assertSame($toolUseIds, array_column($results, 'tool_use_id'));
        $this->assertArrayNotHasKey('is_error', $results[0]);
        $this->assertTrue($results[1]['is_error']);
    }

    public function testRefusalEndsTheRunWithoutTheRefusedTurn(): void {
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::reply(new LLMMessageContents([]), StopReason::REFUSAL, 0.01, ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null]),
        );

        try {
            (new LLMAgentClient())->run($client, $this->request([]), null, new AgentRunOptions());
            $this->fail('Expected AgentRunInterruptedException');
        } catch (AgentRunInterruptedException $e) {
            $this->assertSame(AgentRunInterruptedException::REASON_REFUSAL, $e->getReason());
            $this->assertCount(1, $e->getConversation()->getMessages());
            $this->assertEqualsWithDelta(0.01, $e->getCostUsd(), 1e-12);
        }
    }

    public function testRefusalWithoutOptionsAlsoEndsTheRun(): void {
        $client = new ScriptedLLMClient(ScriptedLLMClient::reply(new LLMMessageContents([]), StopReason::REFUSAL));

        $this->expectException(AgentRunInterruptedException::class);

        (new LLMAgentClient())->run($client, $this->request([]));
    }

    public function testPauseTurnIsResumedWithTheSameConversation(): void {
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::reply(new LLMMessageContents([
                new LLMMessageOpaque('anthropic', ['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'x']]),
            ]), StopReason::PAUSE_TURN),
            ScriptedLLMClient::text('done'),
        );

        $response = (new LLMAgentClient())->run($client, $this->request([]));

        $this->assertSame('done', $response->getLastText());
        $this->assertCount(2, $client->sent);
        $this->assertTrue($client->sent[1]->getLastMessage()->isAssistant());
        $this->assertEquals(new TokenUsage(input: 200, output: 100), $response->getUsage());
    }

    public function testToolMayReturnAPdfThatReachesTheNextModelCall(): void {
        $uploads = new CallbackToolDefinition('uploads', 'Uploads', ['type' => 'object', 'properties' => ['fileId' => ['type' => 'string']], 'required' => ['fileId']],
            fn(array $input): LLMMessageContents => new LLMMessageContents([new LLMMessageText('manual.pdf'), new LLMMessagePdf('base64', 'JVBERi0x')]));
        $client = new ScriptedLLMClient(
            ScriptedLLMClient::toolUse('t1', 'uploads', ['fileId' => 'abc']),
            ScriptedLLMClient::text('Read it.'),
        );

        (new LLMAgentClient())->run($client, $this->request([$uploads]));

        $encoded = (new AnthropicEncoder())->encodeRequest($client->sent[1]);
        $this->assertSame('document', $encoded['messages'][2]['content'][0]['content'][1]['type']);
    }
}
