<?php

namespace Soukicz\Llm\Config;

/**
 * Opt-in caching of the growing conversation history (multi-turn tool loops).
 *
 * Without this option only content explicitly created with the "cached" flag is marked
 * for provider-side prompt caching, so in a tool loop the growing tail of tool calls and
 * tool results is re-billed at the full input price on every turn. With this option the
 * encoder of a provider with explicit cache breakpoints (Anthropic) places a moving
 * breakpoint at the end of the conversation on every request, so each turn reads the
 * previous turns from cache and only pays full price for the newly added messages.
 *
 * Providers that cache prompts automatically and have no explicit breakpoints in their
 * request format (OpenAI, Gemini) ignore this option.
 */
class ConversationCacheConfig {
    public function __construct(
        private readonly ?CacheTtl $ttl = null,
    ) {
    }

    /**
     * Cache entry lifetime for the moving breakpoints (null = provider default, 5 minutes
     * for Anthropic). Longer TTL costs more per cache write.
     */
    public function getTtl(): ?CacheTtl {
        return $this->ttl;
    }
}
