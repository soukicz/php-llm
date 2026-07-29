<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use Soukicz\Llm\Client\ModelInterface;

interface AnthropicNativeToolWithOptions extends AnthropicNativeTool {
    /**
     * Extra fields merged into the serialized tool declaration (e.g. max_characters).
     *
     * @return array<string, mixed>
     */
    public function getAnthropicOptions(ModelInterface $model): array;
}
