<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A translator that can tell who is listening what each of its requests cost, so a job can sum its
 * own spending without reading the shared usage log (where other callers' requests mix in).
 */
interface ReportsCost
{
    /**
     * @param (\Closure(?float): void)|null $listener called with the USD cost of every answered request, or
     *                                               null when its model has no price (the total is then unknown);
     *                                               null stops it
     */
    public function reportCostTo(?\Closure $listener): void;
}
