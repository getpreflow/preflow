<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A list cell shown as Folio's status pill: what a #[ListColumn] method returns instead of a
 * string. The label is escaped; the tone is '' (plain), 'published', 'draft', 'changed' or
 * 'incomplete', and any other value is shown plain.
 */
final class Badge
{
    private const array TONES = ['published', 'draft', 'changed', 'incomplete'];

    public function __construct(
        public readonly string $label,
        public readonly string $tone = '',
    ) {}

    /** The tone the pill is drawn with: '' for none or an unknown one. */
    public function tone(): string
    {
        return in_array($this->tone, self::TONES, true) ? $this->tone : '';
    }
}
