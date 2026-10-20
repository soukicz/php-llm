<?php

namespace Soukicz\Llm\Usage;

/**
 * Token counters of one model call or of a whole agent run.
 *
 * `input` counts uncached input tokens only; cache writes (by TTL) and cache reads are separate, so
 * promptTokens() is the real prompt size. `reasoning` is reported by providers that count thinking
 * tokens separately (OpenAI, Gemini); Anthropic includes them in `output` and leaves it 0.
 */
final readonly class TokenUsage implements \JsonSerializable {
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheWrite5m = 0,
        public int $cacheWrite1h = 0,
        public int $cacheRead = 0,
        public int $reasoning = 0,
    ) {
    }

    public function add(self $other): self {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheWrite5m + $other->cacheWrite5m,
            $this->cacheWrite1h + $other->cacheWrite1h,
            $this->cacheRead + $other->cacheRead,
            $this->reasoning + $other->reasoning,
        );
    }

    public function promptTokens(): int {
        return $this->input + $this->cacheWrite5m + $this->cacheWrite1h + $this->cacheRead;
    }

    /**
     * @return array{input: int, output: int, cacheWrite5m: int, cacheWrite1h: int, cacheRead: int, reasoning: int}
     */
    public function jsonSerialize(): array {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'cacheWrite5m' => $this->cacheWrite5m,
            'cacheWrite1h' => $this->cacheWrite1h,
            'cacheRead' => $this->cacheRead,
            'reasoning' => $this->reasoning,
        ];
    }
}
