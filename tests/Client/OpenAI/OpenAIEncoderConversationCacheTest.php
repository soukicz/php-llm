<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\Config\ConversationCacheConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class OpenAIEncoderConversationCacheTest extends TestCase {
    public function testConversationCacheConfigIsIgnored(): void {
        // OpenAI caches prompts automatically - the option must be a no-op, not an error
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is 2+2?'),
            LLMMessage::createFromAssistant(new LLMMessageContents([
                new LLMMessageToolUse('tool-123', 'calculator', ['expression' => '2+2']),
            ])),
            LLMMessage::createFromUser(new LLMMessageContents([
                new LLMMessageToolResult('tool-123', LLMMessageContents::fromArrayData(['result' => 4])),
            ])),
        ]);

        $encoder = new OpenAIEncoder();

        $encodedWithoutConfig = $encoder->encodeRequest(new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
        ));
        $encodedWithConfig = $encoder->encodeRequest(new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            conversationCacheConfig: new ConversationCacheConfig(),
        ));

        $this->assertEquals($encodedWithoutConfig, $encodedWithConfig);
    }
}
