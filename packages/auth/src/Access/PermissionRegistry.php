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

    /** @var array<string, string> name => reason, for permissions of disabled extensions or parts */
    private array $disabled = [];

    public function declare(string $name, string $label = '', string $group = ''): void
    {
        $this->names[$name] = ['label' => $label, 'group' => $group];
    }

    /** Marks a declared permission as belonging to something switched off ("extension members"): always denied, never an error. */
    public function disable(string $name, string $reason): void
    {
        $this->disabled[$name] = $reason;
    }

    public function disabledReason(string $name): ?string
    {
        return $this->disabled[$name] ?? null;
    }

    /** A family, e.g. "page.*" of a content type; declare its members with declare(). */
    public function declarePattern(string $pattern): void
    {
        $this->patterns[] = $pattern;
    }

    /**
     * Only declared names count: a pattern groups a family (for role editors and
     * role config) but does not declare its members, so a typo stays an error.
     */
    public function isDeclared(string $name): bool
    {
        return isset($this->names[$name]);
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
