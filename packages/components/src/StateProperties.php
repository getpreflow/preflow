<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * Reads and writes the #[State] properties of a component as plain values.
 *
 * @since 0.86.0
 */
final class StateProperties
{
    /** @var array<class-string, list<string>> */
    private static array $names = [];

    /** @return list<string> names of the #[State] properties, cached per class */
    public static function of(object $component): array
    {
        $class = $component::class;
        if (isset(self::$names[$class])) {
            return self::$names[$class];
        }

        $names = [];
        foreach (self::properties($class) as $property) {
            self::check($class, $property);
            $names[] = $property->getName();
        }

        return self::$names[$class] = $names;
    }

    /** @return array<string, mixed> */
    public static function read(object $component): array
    {
        $out = [];
        foreach (self::of($component) as $name) {
            $property = new \ReflectionProperty($component, $name);
            if (!$property->isInitialized($component)) {
                continue;
            }
            $out[$name] = self::plain($component::class, $name, $property->getValue($component));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * A property $state has no value for keeps its own (one added since); a key without a
     * property (one renamed or removed since) refuses the state.
     *
     * @throws StateRefused when the state no longer fits the component's properties
     */
    public static function write(object $component, array $state): void
    {
        // A key the component has no #[State] property for: written before a property was renamed or removed.
        $unknown = array_diff(array_map('strval', array_keys($state)), self::of($component));
        if ($unknown !== []) {
            throw new StateRefused(sprintf('State of %s no longer fits: it has no state property %s.', $component::class, implode(', ', array_map(static fn (string $n): string => '$' . $n, $unknown))));
        }
        foreach (self::of($component) as $name) {
            if (!array_key_exists($name, $state)) {
                continue;
            }
            $property = new \ReflectionProperty($component, $name);
            try {
                $property->setValue($component, self::rebuild($property, $state[$name]));
            } catch (\Throwable $e) {
                throw new StateRefused(
                    sprintf('State of %s::$%s no longer fits: %s', $component::class, $name, $e->getMessage()),
                    $e,
                );
            }
        }
    }

    /**
     * @param class-string $class
     * @return list<\ReflectionProperty>
     */
    private static function properties(string $class): array
    {
        $found = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->isStatic() || $property->getAttributes(State::class) === []) {
                continue;
            }
            $found[] = $property;
        }

        return $found;
    }

    private static function check(string $class, \ReflectionProperty $property): void
    {
        $type = $property->getType();
        if ($type === null) {
            return;
        }

        $parts = $type instanceof \ReflectionNamedType ? [$type] : ($type instanceof \ReflectionUnionType ? $type->getTypes() : []);
        if ($parts === []) {
            self::unsupported($class, $property, (string) $type);
        }

        $classParts = 0;
        $others = 0;
        foreach ($parts as $part) {
            if (!$part instanceof \ReflectionNamedType) {
                self::unsupported($class, $property, (string) $type);
            }
            if ($part->getName() !== 'null') {
                if ($part->isBuiltin()) {
                    $others++;
                } else {
                    $classParts++;
                }
            }
            if ($part->isBuiltin()) {
                if (in_array($part->getName(), ['object', 'callable', 'iterable', 'resource', 'self', 'static'], true)) {
                    self::unsupported($class, $property, (string) $type);
                }
                continue;
            }
            $name = $part->getName();
            if (!is_a($name, \BackedEnum::class, true) && !is_a($name, StateValue::class, true)) {
                self::unsupported($class, $property, (string) $type);
            }
        }

        // A union of a class type with another type cannot be rebuilt from plain values.
        if ($classParts > 0 && $classParts + $others > 1) {
            self::unsupported($class, $property, (string) $type);
        }
    }

    private static function unsupported(string $class, \ReflectionProperty $property, string $type): never
    {
        throw new \LogicException(sprintf(
            '%s::$%s is marked #[State] but its type %s cannot be kept as state. Use scalars, null, arrays of those, backed enums or objects implementing %s.',
            $class,
            $property->getName(),
            $type,
            StateValue::class,
        ));
    }

    private static function plain(string $class, string $name, mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof StateValue) {
            return self::plainArray($class, $name, $value->toState());
        }
        if (is_array($value)) {
            return self::plainArray($class, $name, $value);
        }

        throw new \LogicException(sprintf(
            '%s::$%s holds a %s, which cannot be kept as state.',
            $class,
            $name,
            get_debug_type($value),
        ));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function plainArray(string $class, string $name, array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof \BackedEnum || $value instanceof StateValue) {
                throw new \LogicException(sprintf(
                    '%s::$%s holds a %s inside an array; enums and StateValue objects can only be a property of their own, arrays hold plain values.',
                    $class,
                    $name,
                    get_debug_type($value),
                ));
            }
            $values[$key] = self::plain($class, $name, $value);
        }

        return $values;
    }

    private static function rebuild(\ReflectionProperty $property, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $property->getType();
        $parts = $type instanceof \ReflectionNamedType ? [$type] : ($type instanceof \ReflectionUnionType ? $type->getTypes() : []);
        foreach ($parts as $part) {
            if (!$part instanceof \ReflectionNamedType || $part->isBuiltin()) {
                continue;
            }
            $name = $part->getName();
            if (is_a($name, \BackedEnum::class, true)) {
                return $name::from($value);
            }
            if (is_a($name, StateValue::class, true)) {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('expected an array');
                }
                return $name::fromState($value);
            }
        }

        return $value;
    }
}
