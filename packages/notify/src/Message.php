<?php

declare(strict_types=1);

namespace Preflow\Notify;

interface Message
{
    /** Channel key used for routing, e.g. 'email', 'sms'. */
    public function channel(): string;
}
