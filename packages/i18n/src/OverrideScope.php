<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * Which texts may be overridden: what OverrideRules asks of the application. The adapter that
 * lists the editable texts answers it.
 */
interface OverrideScope
{
    /**
     * The languages offered for the group of a key (two-letter codes).
     *
     * @return list<string>
     */
    public function locales(string $group): array;

    /** Whether an override may be stored for the key in the locale. */
    public function editable(string $key, string $locale): bool;

    /**
     * The placeholder names (without the colon) an override of the key must keep, or null when
     * the key's group is filled in by nobody (any placeholders are fine).
     *
     * @return list<string>|null
     */
    public function placeholders(string $key, string $locale): ?array;
}
