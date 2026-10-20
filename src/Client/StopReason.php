<?php

namespace Soukicz\Llm\Client;

enum StopReason: string {
    case FINISHED = 'finished';
    case TOOL_USE = 'tool_use';
    case LENGTH = 'length';
    case SAFETY = 'safety';
    /** The provider's safety classifier declined the request (HTTP 200); see LLMResponse::getStopDetails() */
    case REFUSAL = 'refusal';
    /** A server-side tool loop paused; re-sending the conversation unchanged resumes it */
    case PAUSE_TURN = 'pause_turn';
}
