<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

enum StreamEventType: string {
    case MESSAGE_START = 'message_start';
    case TEXT_DELTA = 'text_delta';
    case THINKING_DELTA = 'thinking_delta';
    case TOOL_USE_START = 'tool_use_start';
    case TOOL_INPUT_DELTA = 'tool_input_delta';
    case CONTENT_BLOCK_STOP = 'content_block_stop';
    case MESSAGE_COMPLETE = 'message_complete';
}
