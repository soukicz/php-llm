<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Usage\TokenUsage;

class TokenUsageTest extends TestCase {
    public function testAddSumsEveryCounter(): void {
        $sum = (new TokenUsage(1, 2, 3, 4, 5, 6))->add(new TokenUsage(10, 20, 30, 40, 50, 60));

        $this->assertSame(
            ['input' => 11, 'output' => 22, 'cacheWrite5m' => 33, 'cacheWrite1h' => 44, 'cacheRead' => 55, 'reasoning' => 66],
            $sum->jsonSerialize()
        );
    }

    public function testPromptTokensCountUncachedInputAndEveryCacheToken(): void {
        $this->assertSame(1 + 3 + 4 + 5, (new TokenUsage(1, 2, 3, 4, 5, 6))->promptTokens());
    }

    public function testJsonUsesThePropertyNames(): void {
        $this->assertSame(
            '{"input":0,"output":0,"cacheWrite5m":0,"cacheWrite1h":0,"cacheRead":0,"reasoning":0}',
            json_encode(new TokenUsage(), JSON_THROW_ON_ERROR)
        );
    }
}
