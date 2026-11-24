<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * HTML that is already safe to print: every template engine prints it as it is, without
 * escaping. Wrap only markup you built or escaped yourself, never user input.
 */
final class SafeHtml implements \Stringable
{
    public function __construct(public readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
