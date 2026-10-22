<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Screen;

/**
 * What a screen method returns to be rendered in the admin: a template (extending
 * @folio/admin/_layout.twig) and its data. Folio adds prefix, csrf and screen (label, path).
 */
final readonly class ScreenView
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $template,
        public array $data = [],
    ) {}
}
