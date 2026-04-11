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

    /**
     * Register a template function that will be callable from templates.
     */
    public function addFunction(TemplateFunctionDefinition $function): void;

    /**
     * Make a variable available in all templates.
     */
    public function addGlobal(string $name, mixed $value): void;

    /**
     * Get the file extension for this engine's templates (e.g., 'twig', 'blade.php').
     */
    public function getTemplateExtension(): string;
}
