<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * An area of a page layout: a public array property of the layout class, holding blocks. With no
 * "allowed" every block fits, minus "except".
 *
 * @since 0.92.0
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Area
{
    /**
     * @param list<string> $allowed block keys; empty: every block
     * @param list<string> $except  block keys left out
     */
    public function __construct(
        public readonly string $label,
        public readonly array $allowed = [],
        public readonly array $except = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?string $help = null,
    ) {}
}
