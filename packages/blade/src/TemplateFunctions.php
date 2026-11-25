<?php

declare(strict_types=1);

namespace Preflow\Blade;

/**
 * Every template function as a value: `$fn->name(...)` in a Blade template calls the
 * registered function and returns its result as it is (a FormBuilder stays a FormBuilder).
 * The `@name(...)` directive can only print text; this is the way to take an object.
 */
final class TemplateFunctions
{
    /** @var array<string, callable> */
    private array $functions = [];

    public function add(string $name, callable $callable): void
    {
        $this->functions[$name] = $callable;
    }

    /** @param array<int|string, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if (!isset($this->functions[$name])) {
            $known = $this->functions === [] ? 'none registered yet' : implode(', ', array_keys($this->functions));

            throw new \LogicException(
                "Template function '{$name}' is not registered, so \$fn->{$name}(...) can't call it. "
                . "Known functions: {$known}. Register it with BladeEngine::addFunction(new TemplateFunctionDefinition(...))."
            );
        }

        return ($this->functions[$name])(...$arguments);
    }

    /**
     * What a `@name(...)` directive prints: the value when it is text (string, Stringable,
     * scalar or null), otherwise a refusal that names the `$fn->name(...)` form.
     */
    public static function text(string $name, mixed $value): string|\Stringable|int|float|bool|null
    {
        if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
            return $value;
        }

        $type = get_debug_type($value);

        throw new \LogicException(
            "{$name} returns a {$type}, not text: @{$name} can't print it. "
            . "Take it as a value: @php(\$x = \$fn->{$name}(...))"
        );
    }
}
