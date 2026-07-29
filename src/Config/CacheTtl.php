<?php

namespace Soukicz\Llm\Config;

enum CacheTtl: string {
    case FIVE_MINUTES = '5m';
    case ONE_HOUR = '1h';
}
