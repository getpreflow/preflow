<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\Validation\Rules\Between;
use Preflow\Validation\Rules\Confirmed;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\IsInteger;
use Preflow\Validation\Rules\MaxLength;
use Preflow\Validation\Rules\MinLength;
use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\Rules\Numeric;
use Preflow\Validation\Rules\Regex;
use Preflow\Validation\Rules\Required;
use Preflow\Validation\Rules\Url;

final class RuleFactory
{
    /** @var array<string, string> Alias => FQCN */
    private array $aliases = [];

    private const BUILT_IN = [
        'required' => Required::class,
        'nullable' => Nullable::class,
        'email' => Email::class,
        'url' => Url::class,
        'numeric' => Numeric::class,
        'integer' => IsInteger::class,
        'min' => MinLength::class,
        'max' => MaxLength::class,
        'between' => Between::class,
        'in' => InList::class,
        'regex' => Regex::class,
        'confirmed' => Confirmed::class,
    ];

    /**
     * Resolve a rule string or instance to a ValidationRule.
     *
     * Resolution order:
     * 1. Already a ValidationRule instance — return as-is
     * 2. Registered aliases (app-level overrides)
     * 3. Built-in aliases
     * 4. FQCN fallback
     */
    public function resolve(string|ValidationRule $rule): ValidationRule
    {
        if ($rule instanceof ValidationRule) {
            return $rule;
        }

        // Parse "alias:param1,param2" format
        $parts = explode(':', $rule, 2);
        $alias = $parts[0];
        $paramString = $parts[1] ?? null;

        // Check registered aliases first (app overrides)
        if (isset($this->aliases[$alias])) {
            return $this->instantiate($this->aliases[$alias], $alias, $paramString);
        }

        // Check built-in aliases
        if (isset(self::BUILT_IN[$alias])) {
            return $this->instantiate(self::BUILT_IN[$alias], $alias, $paramString);
        }

        // FQCN fallback
        if (class_exists($rule)) {
            return new $rule();
        }

        throw new \RuntimeException("Unknown validation rule: {$rule}");
    }

    /**
     * Register a rule class under an alias. Later registrations override earlier ones.
     */
    public function register(string $alias, string $ruleClass): void
    {
        $this->aliases[$alias] = $ruleClass;
    }

    /**
     * Discover rule aliases from class list by reading #[RuleAlias] attributes.
     *
     * @param list<class-string> $classes
     */
    public function discover(array $classes): void
    {
        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $attrs = $ref->getAttributes(RuleAlias::class);
            if ($attrs !== []) {
                $alias = $attrs[0]->newInstance()->alias;
                $this->aliases[$alias] = $class;
            }
        }
    }

    private function instantiate(string $class, string $alias, ?string $paramString): ValidationRule
    {
        if ($paramString === null) {
            return new $class();
        }

        // Special handling for regex — don't split on commas inside the pattern
        if ($alias === 'regex') {
            return new $class($paramString);
        }

        $params = explode(',', $paramString);

        // Cast numeric params
        $params = array_map(function (string $p): string|int|float {
            if (ctype_digit($p) || (str_starts_with($p, '-') && ctype_digit(substr($p, 1)))) {
                return (int) $p;
            }
            if (is_numeric($p)) {
                return (float) $p;
            }
            return $p;
        }, $params);

        // For InList, pass the full array as a single argument
        if ($alias === 'in') {
            return new $class($params);
        }

        return new $class(...$params);
    }
}
