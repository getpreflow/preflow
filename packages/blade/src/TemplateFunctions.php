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
     * What the `@name(...)` directive of a safe function (TemplateFunctionDefinition::$isSafe)
     * prints: the text text() lets through, unescaped. An Htmlable prints its toHtml(), a
     * DeferringDisplayableValue what it resolves to, a backed enum as e() prints it (its value,
     * escaped); other text as it is.
     */
    public static function safe(string $name, mixed $value): string
    {
        $value = self::text($name, $value);
        if ($value instanceof \Illuminate\Contracts\Support\DeferringDisplayableValue) {
            $value = self::text($name, $value->resolveDisplayableValue());
        }

        return match (true) {
            $value instanceof \Illuminate\Contracts\Support\Htmlable => $value->toHtml(),
            $value instanceof \BackedEnum => e($value),
            $value === null => '',
            $value instanceof \Stringable => (string) $value,
            is_bool($value) => $value ? '1' : '',
            default => (string) $value,
        };
    }

    /**
     * What a `@name(...)` directive prints: the value when it is text (string, Stringable,
     * scalar, null, Htmlable, BackedEnum: what e() prints), otherwise a refusal that names the `$fn->name(...)` form.
     */
    public static function text(string $name, mixed $value): mixed
    {
        if (
            $value === null
            || is_scalar($value)
            || $value instanceof \Stringable
            || $value instanceof \Illuminate\Contracts\Support\Htmlable
            || $value instanceof \Illuminate\Contracts\Support\DeferringDisplayableValue
            || $value instanceof \BackedEnum
        ) {
            return $value;
        }

        $type = is_object($value) ? 'an object of class ' . $value::class : 'a value of type ' . get_debug_type($value);

        throw new \LogicException(
            "{$name} returns {$type}, not text: @{$name} can't print it. "
            . "Take it as a value: @php(\$x = \$fn->{$name}(...))"
        );
    }
}
