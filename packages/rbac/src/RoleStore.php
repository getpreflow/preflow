<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/** The rbac_role records, loaded once per instance (one per request). */
final class RoleStore
{
    /** @var array<string, array<string, list<string>>>|null population => role name => patterns */
    private ?array $roles = null;

    public function __construct(private readonly DataManager $dm) {}

    /** @return array<string, list<string>> role name => permission patterns */
    public function rolesFor(string $population): array
    {
        return $this->all()[$population] ?? [];
    }

    /**
     * Creates the role when the population has none of that name; an existing role is never
     * changed (sites seed their roles with it, editors own them afterwards).
     *
     * @param list<string> $permissions
     */
    public function ensure(string $population, string $name, string $label, array $permissions): void
    {
        if (isset($this->rolesFor($population)[$name])) {
            return;
        }
        $type = $this->dm->getTypeRegistry()->get('rbac_role');
        $this->dm->saveType(DynamicRecord::fromArray($type, [
            $type->idField => bin2hex(random_bytes(16)),
            'name' => $name,
            'label' => $label,
            'population' => $population,
            'permissions' => RoleList::normalize($permissions),
        ]), false);
        $this->roles = null;
    }

    /** @return array<string, array<string, list<string>>> */
    private function all(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $roles = [];
        foreach ($this->dm->queryType('rbac_role')->get()->items() as $record) {
            $population = $record->get('population');
            $name = $record->get('name');
            if (!is_string($population) || !is_string($name) || trim($name) === '') {
                continue; // malformed role records grant nothing
            }
            $roles[$population][trim($name)] = RoleList::normalize($record->get('permissions'));
        }

        return $this->roles = $roles;
    }
}
