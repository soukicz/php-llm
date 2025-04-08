<?php

namespace Soukicz\Llm\Client;

enum StopReason: string {
    case FINISHED = 'finished';
    case TOOL_USE = 'tool_use';
    case LENGTH = 'length';
    case SAFETY = 'safety';
}
