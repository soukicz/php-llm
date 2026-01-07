<?php

namespace Soukicz\Llm\Config;

enum ReasoningEffort: string {
    case NONE = 'none';
    case MINIMAL = 'minimal';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case EXTRA_HIGH = 'extra_high';
}
