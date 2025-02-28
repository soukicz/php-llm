<?php

namespace Soukicz\Llm;

interface JsonDeserializable extends \JsonSerializable {
    public static function fromJson(array $data): self;
}
