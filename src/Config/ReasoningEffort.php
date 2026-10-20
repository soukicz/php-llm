<?php

namespace Soukicz\Llm\Config;

enum ReasoningEffort: string {
    case NONE = 'none';
    case MINIMAL = 'minimal';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    /** Between HIGH and MAX (Anthropic `xhigh`, OpenAI `xhigh`) */
    case XHIGH = 'xhigh';
    /** Kept for compatibility: Anthropic `max`, OpenAI `xhigh` */
    case EXTRA_HIGH = 'extra_high';
    /** The provider's highest level (Anthropic `max`) */
    case MAX = 'max';
}
