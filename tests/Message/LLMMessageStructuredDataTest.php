<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Message;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Message\LLMMessageStructuredData;

class LLMMessageStructuredDataTest extends TestCase {
    public function testGetData(): void {
        $data = ['name' => 'John', 'age' => 30];
        $message = new LLMMessageStructuredData($data, '{"name":"John","age":30}');

        $this->assertEquals($data, $message->getData());
    }

    public function testGetRawJson(): void {
        $rawJson = '{"name":"John","age":30}';
        $message = new LLMMessageStructuredData(['name' => 'John', 'age' => 30], $rawJson);

        $this->assertEquals($rawJson, $message->getRawJson());
    }

    public function testIsCachedDefaultFalse(): void {
        $message = new LLMMessageStructuredData([], '{}');

        $this->assertFalse($message->isCached());
    }

    public function testIsCachedTrue(): void {
        $message = new LLMMessageStructuredData([], '{}', true);

        $this->assertTrue($message->isCached());
    }

    public function testJsonSerializeRoundTrip(): void {
        $data = ['name' => 'John', 'items' => [1, 2, 3]];
        $rawJson = '{"name":"John","items":[1,2,3]}';
        $original = new LLMMessageStructuredData($data, $rawJson, true);

        $serialized = $original->jsonSerialize();
        $restored = LLMMessageStructuredData::fromJson($serialized);

        $this->assertEquals($original->getData(), $restored->getData());
        $this->assertEquals($original->getRawJson(), $restored->getRawJson());
        $this->assertEquals($original->isCached(), $restored->isCached());
    }

    public function testFromJsonWithoutCached(): void {
        $restored = LLMMessageStructuredData::fromJson([
            'data' => ['key' => 'value'],
            'rawJson' => '{"key":"value"}',
        ]);

        $this->assertFalse($restored->isCached());
    }
}
