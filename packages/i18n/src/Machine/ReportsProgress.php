<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A translator that can tell who is listening how far a call to translateUnits() has come, so a
 * job translating a long record (minutes, many requests) can show more than "running".
 */
interface ReportsProgress
{
    /**
     * @param (\Closure(int, int): void)|null $listener called with (units done, units in the call) each
     *                                                  time a request's units are through, translated or
     *                                                  failed; null stops it
     */
    public function reportProgressTo(?\Closure $listener): void;
}
