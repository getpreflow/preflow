<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

/**
 * Every permission is declared (by the app, a package or a Folio type), so a
 * role editor can list them and a typo in a check is caught.
 */
final class PermissionRegistry
{
    /** @var array<string, array{label: string, group: string}> */
    private array $names = [];

    /** @var list<string> */
    private array $patterns = [];

    public function declare(string $name, string $label = '', string $group = ''): void
    {
        $this->names[$name] = ['label' => $label, 'group' => $group];
    }

    /** A family, e.g. "page.*" declared by a content type. */
    public function declarePattern(string $pattern): void
    {
        $this->patterns[] = $pattern;
    }

    public function isDeclared(string $name): bool
    {
        if (isset($this->names[$name])) {
            return true;
        }
        foreach ($this->patterns as $pattern) {
            if (Pattern::matches($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{label: string, group: string}> */
    public function all(): array
    {
        return $this->names;
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
