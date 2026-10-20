<?php

namespace Soukicz\Llm\Config;

/**
 * What thinking blocks contain (Anthropic `thinking.display`). It controls visibility only; thinking is billed
 * the same. UPDATES returns the model's short between-tool progress notes as thinking text and needs a beta
 * header, which the Anthropic client adds.
 */
enum ThinkingDisplay: string {
    case SUMMARIZED = 'summarized';
    case UPDATES = 'updates';
    case OMITTED = 'omitted';
}
