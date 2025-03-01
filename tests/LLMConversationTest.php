<?php

namespace Soukicz\Llm\Tests;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class LLMConversationTest extends TestCase {
    public function testJsonSerializationAndDeserialization(): void {
        // Create content objects of different types
        $textContent = new LLMMessageText('Hello, this is a text message', false);
        $imageContent = new LLMMessageImage('base64', 'image/jpeg', 'c29tZWltYWdlZGF0YQ==', false);
        $pdfContent = new LLMMessagePdf('base64', 'c29tZXBkZmRhdGE=', false);
        $reasoningContent = new LLMMessageReasoning('Let me think about this...', 'sig123', false);
        $toolUseContent = new LLMMessageToolUse('tool-123', 'calculator', ['expression' => '2+2'], false);
        $toolResultContent = new LLMMessageToolResult('tool-123', ['result' => 4], false);

        // Create messages with different content types
        $systemMessage = LLMMessage::createFromSystem([$textContent]);
        $userMessage = LLMMessage::createFromUser([$textContent, $imageContent, $pdfContent]);
        $assistantMessage = LLMMessage::createFromAssistant([
            $textContent,
            $reasoningContent,
            $toolUseContent,
            $toolResultContent,
        ]);
        $userContinueMessage = LLMMessage::createFromUserContinue($textContent);

        // Create a conversation with the messages
        $conversation = new LLMConversation([
            $systemMessage,
            $userMessage,
            $assistantMessage,
            $userContinueMessage,
        ]);

        // Serialize to JSON and then back to array
        $json = json_encode($conversation, JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        // Create a new conversation from the JSON data
        $deserializedConversation = LLMConversation::fromJson($data);

        // Assert that the deserialized conversation has the same number of messages
        $originalMessages = $conversation->getMessages();
        $deserializedMessages = $deserializedConversation->getMessages();

        $this->assertCount(count($originalMessages), $deserializedMessages);

        // Test individual messages
        foreach ($originalMessages as $index => $originalMessage) {
            $deserializedMessage = $deserializedMessages[$index];

            // Check message type
            $this->assertEquals($originalMessage->isSystem(), $deserializedMessage->isSystem());
            $this->assertEquals($originalMessage->isUser(), $deserializedMessage->isUser());
            $this->assertEquals($originalMessage->isAssistant(), $deserializedMessage->isAssistant());
            $this->assertEquals($originalMessage->isContinue(), $deserializedMessage->isContinue());

            // Check content
            $originalContents = $originalMessage->getContents();
            $deserializedContents = $deserializedMessage->getContents();

            $this->assertCount(count($originalContents), $deserializedContents);

            // Check each content type
            foreach ($originalContents as $contentIndex => $originalContent) {
                $deserializedContent = $deserializedContents[$contentIndex];

                // Check the type of content
                $this->assertInstanceOf(get_class($originalContent), $deserializedContent);

                // Check the cached property
                $this->assertEquals($originalContent->isCached(), $deserializedContent->isCached());

                // Specific assertions based on content type
                if ($originalContent instanceof LLMMessageText) {
                    $this->assertEquals($originalContent->getText(), $deserializedContent->getText());
                } elseif ($originalContent instanceof LLMMessageImage) {
                    $this->assertEquals($originalContent->getEncoding(), $deserializedContent->getEncoding());
                    $this->assertEquals($originalContent->getMediaType(), $deserializedContent->getMediaType());
                    $this->assertEquals($originalContent->getData(), $deserializedContent->getData());
                } elseif ($originalContent instanceof LLMMessagePdf) {
                    $this->assertEquals($originalContent->getEncoding(), $deserializedContent->getEncoding());
                    $this->assertEquals($originalContent->getData(), $deserializedContent->getData());
                } elseif ($originalContent instanceof LLMMessageReasoning) {
                    $this->assertEquals($originalContent->getText(), $deserializedContent->getText());
                    $this->assertEquals($originalContent->getSignature(), $deserializedContent->getSignature());
                } elseif ($originalContent instanceof LLMMessageToolUse) {
                    $this->assertEquals($originalContent->getId(), $deserializedContent->getId());
                    $this->assertEquals($originalContent->getName(), $deserializedContent->getName());
                    $this->assertEquals($originalContent->getInput(), $deserializedContent->getInput());
                } elseif ($originalContent instanceof LLMMessageToolResult) {
                    $this->assertEquals($originalContent->getId(), $deserializedContent->getId());
                    $this->assertEquals($originalContent->getContent(), $deserializedContent->getContent());
                }
            }
        }
    }

    public function testWithMessage(): void {
        // Create initial conversation
        $textContent = new LLMMessageText('Initial message', false);
        $systemMessage = LLMMessage::createFromSystem([$textContent]);
        $conversation = new LLMConversation([$systemMessage]);

        // Add a new message
        $newTextContent = new LLMMessageText('New message', false);
        $userMessage = LLMMessage::createFromUser([$newTextContent]);
        $updatedConversation = $conversation->withMessage($userMessage);

        // Assert that the original conversation is unchanged
        $this->assertCount(1, $conversation->getMessages());

        // Assert that the new conversation has the additional message
        $this->assertCount(2, $updatedConversation->getMessages());

        // Verify the new message is in the updated conversation
        $messages = $updatedConversation->getMessages();
        $this->assertTrue($messages[0]->isSystem());
        $this->assertTrue($messages[1]->isUser());

        // Test JSON serialization and deserialization of updated conversation
        $json = json_encode($updatedConversation, JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $deserializedConversation = LLMConversation::fromJson($data);

        $this->assertCount(2, $deserializedConversation->getMessages());
    }
}
