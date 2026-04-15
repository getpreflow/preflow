<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class ValidationExtensionProvider implements TemplateExtensionProvider
{
    private ?ErrorBag $errorBag = null;

    /** @var array<string, mixed> */
    private array $oldInput = [];

    public function setErrorBag(ErrorBag $errorBag): void
    {
        $this->errorBag = $errorBag;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function setOldInput(array $input): void
    {
        $this->oldInput = $input;
    }

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'validation_errors',
                callable: fn (?string $field = null): string|array|null =>
                    $field !== null
                        ? $this->errorBag?->first($field)
                        : ($this->errorBag?->toArray() ?? []),
            ),
            new TemplateFunctionDefinition(
                name: 'validation_has_errors',
                callable: fn (?string $field = null): bool =>
                    $field !== null
                        ? ($this->errorBag?->has($field) ?? false)
                        : ($this->errorBag !== null && !$this->errorBag->isEmpty()),
            ),
            new TemplateFunctionDefinition(
                name: 'old',
                callable: fn (string $field, mixed $default = null): mixed =>
                    $this->oldInput[$field] ?? $default,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }
}
