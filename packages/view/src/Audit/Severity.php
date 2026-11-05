<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * How bad a finding is. Critical: search engines or visitors are hurt (a broken
 * link, a 5xx, a missing title). Warning: clearly wrong but not harmful.
 * Notice: best practice.
 *
 * @since 0.54
 */
enum Severity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Notice = 'notice';
}
