<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * A check across pages (duplicate titles, hreflang pairs, broken links): it sees
 * every audited page through collect() and reports once all are done, in
 * finish(). A run can stop and continue in a later process, so what it has
 * collected so far must survive as plain data: state() gives it (JSON-safe),
 * restore() takes it back before the run continues. Register it with
 * AuditRegistry::addRun().
 *
 * @since 0.54
 */
interface RunCheck
{
    /** A stable id ('title.duplicate'); findings name it. */
    public function id(): string;

    public function collect(RenderedPage $page): void;

    /** @return list<Finding> */
    public function finish(): array;

    /** @return array<array-key, mixed> what was collected so far, JSON-safe */
    public function state(): array;

    /** @param array<array-key, mixed> $state what state() gave */
    public function restore(array $state): void;
}
