<?php

declare(strict_types=1);

namespace Preflow\View;

interface TemplateEngineInterface
{
    /**
     * Render a template file with the given context variables.
     *
     * @param string               $template Path to the template file
     * @param array<string, mixed> $context  Variables available in the template
     * @return string Rendered HTML
     */
    public function render(string $template, array $context = []): string;

    /**
     * Check if a template exists.
     */
    public function exists(string $template): bool;
}
