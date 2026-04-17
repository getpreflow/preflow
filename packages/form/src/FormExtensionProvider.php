<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Validation\ErrorBag;
use Preflow\View\FormHypermediaDriver;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class FormExtensionProvider implements TemplateExtensionProvider
{
    private ?ErrorBag $errorBag = null;
    /** @var array<string, mixed> */
    private array $oldInput = [];
    private ?FormHypermediaDriver $driver = null;
    private ?string $componentId = null;
    private ?string $csrfToken = null;
    private ?string $validateEndpoint = null;

    public function __construct(
        private readonly FieldRenderer $fieldRenderer,
        private readonly GroupRenderer $groupRenderer,
        private readonly ModelIntrospector $introspector,
    ) {}

    public function setErrorBag(ErrorBag $errorBag): void
    {
        $this->errorBag = $errorBag;
    }

    /** @param array<string, mixed> $input */
    public function setOldInput(array $input): void
    {
        $this->oldInput = $input;
    }

    public function setDriver(FormHypermediaDriver $driver): void
    {
        $this->driver = $driver;
    }

    public function setComponentContext(string $componentId, ?string $validateEndpoint = null): void
    {
        $this->componentId = $componentId;
        $this->validateEndpoint = $validateEndpoint;
    }

    public function setCsrfToken(string $token): void
    {
        $this->csrfToken = $token;
    }

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'form_begin',
                callable: fn (array $options = []): FormBuilder => $this->createBuilder($options),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'form_end',
                callable: fn (): string => '</form>',
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    /** @param array<string, mixed> $options */
    private function createBuilder(array $options): FormBuilder
    {
        $defaults = [];
        if ($this->errorBag !== null && !isset($options['errorBag'])) {
            $defaults['errorBag'] = $this->errorBag;
        }
        if ($this->oldInput !== [] && !isset($options['oldInput'])) {
            $defaults['oldInput'] = $this->oldInput;
        }
        if ($this->driver !== null && !isset($options['driver'])) {
            $defaults['driver'] = $this->driver;
        }
        if ($this->componentId !== null && !isset($options['componentId'])) {
            $defaults['componentId'] = $this->componentId;
        }
        if ($this->csrfToken !== null && !isset($options['csrf_token'])) {
            $defaults['csrf_token'] = $this->csrfToken;
        }
        if ($this->validateEndpoint !== null) {
            $defaults['validate_endpoint'] = $this->validateEndpoint;
        }

        return new FormBuilder(
            fieldRenderer: $this->fieldRenderer,
            groupRenderer: $this->groupRenderer,
            introspector: $this->introspector,
            options: array_merge($defaults, $options),
        );
    }
}
