<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Data\Model;
use Preflow\Validation\ErrorBag;
use Preflow\View\FormHypermediaDriver;

final class FormBuilder
{
    private readonly ?Model $model;
    private readonly ?string $scenario;
    private readonly ?ErrorBag $errorBag;
    /** @var array<string, string> */
    private readonly array $oldInput;
    /** @var array<string, list<string>> */
    private readonly array $rules;
    private readonly string $action;
    private readonly string $method;
    private readonly ?string $csrfToken;
    /** @var array<string, string> */
    private readonly array $formAttrs;
    private readonly ?FormHypermediaDriver $driver;
    private readonly ?string $componentId;
    private readonly ?string $validateEndpoint;

    /**
     * Group stack for capturing field output.
     * Each entry: ['options' => array, 'content' => string]
     *
     * @var list<array{options: array<string,mixed>, content: string}>
     */
    private array $groupStack = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly FieldRenderer $fieldRenderer,
        private readonly GroupRenderer $groupRenderer,
        private readonly ModelIntrospector $introspector,
        array $options = [],
    ) {
        $this->model            = $options['model']             ?? null;
        $this->scenario         = $options['scenario']          ?? null;
        $this->errorBag         = $options['errorBag']          ?? null;
        $this->oldInput         = $options['oldInput']          ?? [];
        $this->rules            = $options['rules']             ?? [];
        $this->action           = $options['action']            ?? '';
        $this->method           = strtolower($options['method'] ?? 'post');
        $this->csrfToken        = $options['csrf_token']        ?? null;
        $this->formAttrs        = $options['attrs']             ?? [];
        $this->driver           = $options['driver']            ?? null;
        $this->componentId      = $options['componentId']       ?? null;
        $this->validateEndpoint = $options['validate_endpoint'] ?? null;
    }

    // ------------------------------------------------------------------
    // Form open/close
    // ------------------------------------------------------------------

    /**
     * Render the opening <form> tag, optional driver attrs, user attrs, and CSRF hidden input.
     */
    public function begin(): string
    {
        $attrs = ['method' => $this->method];

        if ($this->action !== '') {
            $attrs['action'] = $this->action;
        }

        // Driver may supply hx-post, hx-target, etc. — only in component context
        if ($this->driver !== null && $this->componentId !== null && $this->action !== '') {
            $driverAttrs = $this->driver->formAttributes($this->action, $this->method);
            $attrs = array_merge($attrs, $driverAttrs);
        }

        // User attrs always win
        $attrs = array_merge($attrs, $this->formAttrs);

        $html = '<form' . $this->buildAttrString($attrs) . '>';

        if ($this->csrfToken !== null) {
            $safeName  = htmlspecialchars('_csrf_token', ENT_QUOTES, 'UTF-8');
            $safeValue = htmlspecialchars($this->csrfToken, ENT_QUOTES, 'UTF-8');
            $html .= "\n" . '<input type="hidden" name="' . $safeName . '" value="' . $safeValue . '">';
        }

        return $html;
    }

    /**
     * Render the closing </form> tag.
     */
    public function end(): string
    {
        return '</form>';
    }

    // ------------------------------------------------------------------
    // Field rendering
    // ------------------------------------------------------------------

    /**
     * Render a field block (wrapper + label + input + errors).
     *
     * When inside a group (capturing=true), the rendered HTML is buffered
     * and an empty string is returned; call endGroup() to flush and render.
     *
     * @param array<string, mixed> $options
     */
    public function field(string $name, array $options = []): string
    {
        $resolved = $this->resolveFieldOptions($name, $options);
        $html     = $this->fieldRenderer->renderField($name, $resolved);

        if ($this->isCapturing()) {
            $this->appendToGroup($html);
            return '';
        }

        return $html;
    }

    /**
     * Convenience: render a <select> field.
     *
     * @param array<string, mixed> $options
     */
    public function select(string $name, array $options = []): string
    {
        $options['type'] = 'select';
        return $this->field($name, $options);
    }

    /**
     * Convenience: render a checkbox field.
     *
     * @param array<string, mixed> $options
     */
    public function checkbox(string $name, array $options = []): string
    {
        $options['type'] = 'checkbox';
        return $this->field($name, $options);
    }

    /**
     * Convenience: render a radio field.
     *
     * @param array<string, mixed> $options
     */
    public function radio(string $name, array $options = []): string
    {
        $options['type'] = 'radio';
        return $this->field($name, $options);
    }

    /**
     * Convenience: render a file input field.
     *
     * @param array<string, mixed> $options
     */
    public function file(string $name, array $options = []): string
    {
        $options['type'] = 'file';
        return $this->field($name, $options);
    }

    /**
     * Render a hidden input (no label wrapper).
     */
    public function hidden(string $name, string $value = ''): string
    {
        return $this->field($name, ['type' => 'hidden', 'value' => $value]);
    }

    /**
     * Render a submit button.
     *
     * @param array<string, mixed> $options
     */
    public function submit(string $label = 'Submit', array $options = []): string
    {
        $attrs = ['type' => 'submit'];

        // Driver may add hx-include, etc.
        if ($this->driver !== null && $this->componentId !== null) {
            $driverAttrs = $this->driver->submitAttributes($this->componentId, $options);
            $attrs       = array_merge($attrs, $driverAttrs);
        }

        // User attrs win
        $userAttrs = $options['attrs'] ?? [];
        $attrs     = array_merge($attrs, $userAttrs);

        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<button' . $this->buildAttrString($attrs) . '>' . $safeLabel . '</button>';
    }

    // ------------------------------------------------------------------
    // Group support
    // ------------------------------------------------------------------

    /**
     * Begin capturing fields into a group.
     *
     * @param array<string, mixed> $options Options forwarded to GroupRenderer::render()
     */
    public function group(array $options = []): void
    {
        $this->groupStack[] = ['options' => $options, 'content' => ''];
    }

    /**
     * End the current group, render it via GroupRenderer, and return the HTML.
     * If no group is open, returns an empty string.
     */
    public function endGroup(): string
    {
        if ($this->groupStack === []) {
            return '';
        }

        $group = array_pop($this->groupStack);

        return $this->groupRenderer->render($group['content'], $group['options']);
    }

    // ------------------------------------------------------------------
    // Auto-field generation
    // ------------------------------------------------------------------

    /**
     * Auto-generate all fields from the bound model's #[Validate] attributes.
     *
     * @param array{only?: list<string>, except?: list<string>, overrides?: array<string, array<string,mixed>>} $options
     */
    public function fields(array $options = []): string
    {
        if ($this->model === null) {
            return '';
        }

        $only     = $options['only']      ?? null;
        $except   = $options['except']    ?? [];
        $overrides = $options['overrides'] ?? [];

        $modelClass = $this->model::class;
        $fieldMap   = $this->introspector->getFields($modelClass, $this->scenario);

        $html = '';

        foreach (array_keys($fieldMap) as $name) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            if (in_array($name, $except, true)) {
                continue;
            }

            $fieldOptions = $overrides[$name] ?? [];
            $html .= $this->field($name, $fieldOptions);
        }

        return $html;
    }

    // ------------------------------------------------------------------
    // Internal: option resolution
    // ------------------------------------------------------------------

    /**
     * Merge explicit options with model-derived metadata.
     *
     * Priority for value: old input > model property > explicit option.
     * Priority for type:  explicit > model-inferred.
     * Priority for attrs: explicit user attrs override driver-injected attrs.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function resolveFieldOptions(string $name, array $options): array
    {
        $modelClass = $this->model !== null ? $this->model::class : null;

        // ---- type ----
        if (!isset($options['type']) && $modelClass !== null) {
            $options['type'] = $this->introspector->inferType($name, $modelClass, $this->scenario);
        }

        $type = $options['type'] ?? 'text';

        // ---- required ----
        if (!isset($options['required'])) {
            if (isset($this->rules[$name])) {
                // Form-level rule override: required only if 'required' is in the list
                $options['required'] = in_array('required', $this->rules[$name], true);
            } elseif ($modelClass !== null) {
                $options['required'] = $this->introspector->isRequired($name, $modelClass, $this->scenario);
            }
        }

        // ---- select options ----
        // Top-level 'options' key is a convenience alias for attrs['options']
        if ($type === 'select' && isset($options['options'])) {
            $options['attrs']['options'] = $options['options'];
            unset($options['options']);
        }
        // Fall back to 'in:' rule from model when no explicit options provided
        if ($type === 'select' && !isset($options['attrs']['options']) && $modelClass !== null) {
            $inOptions = $this->introspector->getInOptions($name, $modelClass, $this->scenario);
            if ($inOptions !== null) {
                $options['attrs']['options'] = array_combine($inOptions, $inOptions);
            }
        }

        // ---- value ----
        if (!isset($options['value']) && $type !== 'hidden') {
            if (array_key_exists($name, $this->oldInput)) {
                $options['value'] = (string) $this->oldInput[$name];
            } elseif ($this->model !== null) {
                $values = $this->introspector->getValues($this->model);
                if (array_key_exists($name, $values)) {
                    $options['value'] = (string) $values[$name];
                }
            }
        }

        // ---- errors ----
        if (!isset($options['errors']) && $this->errorBag !== null && $this->errorBag->has($name)) {
            $options['errors'] = $this->errorBag->get($name);
        }

        // ---- hypermedia inline validation ----
        if (
            $this->driver !== null
            && $this->componentId !== null
            && $this->validateEndpoint !== null
            && ($options['validate'] ?? true) !== false
        ) {
            $driverAttrs  = $this->driver->inlineValidationAttributes($this->validateEndpoint, $name);
            $explicitAttrs = $options['attrs'] ?? [];
            // User attrs override driver attrs
            $options['attrs'] = array_merge($driverAttrs, $explicitAttrs);
        }

        return $options;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function isCapturing(): bool
    {
        return $this->groupStack !== [];
    }

    private function appendToGroup(string $html): void
    {
        $last                          = array_key_last($this->groupStack);
        $this->groupStack[$last]['content'] .= $html;
    }

    /**
     * Build an HTML attribute string from an associative array.
     * Boolean true renders as a bare attribute; false/null omits the attribute.
     *
     * @param array<string, mixed> $attrs
     */
    private function buildAttrString(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $key => $val) {
            if ($val === false || $val === null) {
                continue;
            }

            $safeKey = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');

            if ($val === true) {
                $parts[] = $safeKey;
            } else {
                $parts[] = $safeKey . '="' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return $parts !== [] ? ' ' . implode(' ', $parts) : '';
    }
}
