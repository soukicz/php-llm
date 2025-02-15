<?php

namespace Soukicz\PhpLlm\Client;

use Soukicz\PhpLlm\LLMRequest;

interface LLMBatchClient extends LLMClient {

    /**
     * @param LLMRequest[] $requests
     * @return string batch id
     */
    public function createBatch(array $requests): string;

    public function retrieveBatch(string $batchId): ?array;

    public function getCode(): string;
}
