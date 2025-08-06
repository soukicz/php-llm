<?php

namespace Soukicz\Llm\Client\Universal;

use Soukicz\Llm\Client\ModelInterface;

class LocalModel implements ModelInterface {

    public function __construct(
        readonly private string $code
    ) {
    }

    public function getCode(): string {
        return $this->code;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.0;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
