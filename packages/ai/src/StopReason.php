<?php

declare(strict_types=1);

namespace Preflow\Ai;

enum StopReason: string
{
    case Complete = 'complete';
    case Blocked = 'blocked';     // the provider's safety or recitation filter withheld the answer
    case Truncated = 'truncated'; // the output limit cut the answer off
    case Refused = 'refused';     // the model declined
}
