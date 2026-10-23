<?php

declare(strict_types=1);

namespace Preflow\Rbac\Fields;

use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;

/**
 * A user's roles ("rbac_roles"): checkboxes of the roles of the user type's population
 * ("users" of the type) or of the field's "population". The value is a list of role names;
 * a stored role no longer defined stays checked under its name, so saving keeps it.
 * On a staff account (StaffRules) a role whose patterns the signed-in user doesn't cover
 * (PermissionCoverage) is disabled ("You can't grant this"); StaffRules refuses it anyway.
 */
final class RolesFieldType implements FieldType
{
    private const string DENIED = "You can't grant this";

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly ?PermissionCoverage $coverage = null,
    ) {}

    public function key(): string
    {
        return 'rbac_roles';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $selected = RoleList::normalize($ctx->value);
        $population = is_string($ctx->config['population'] ?? null)
            ? $ctx->config['population']
            : $this->catalog->get((string) ($ctx->record['type'] ?? ''))?->users;
        $roles = $population !== null ? $this->roles($population) : [];
        foreach ($selected as $name) {
            $roles[$name] ??= $name . ' (not defined)';
        }
        $name = $e($ctx->name . '[]');
        $held = ($ctx->record['type'] ?? null) === StaffManagers::TYPE ? $this->coverage?->held() : null;
        $patterns = $held !== null ? (new RoleStore($this->dm))->rolesFor('staff') : [];

        $html = '<fieldset class="form-group rbac-roles' . ($ctx->errors !== [] ? ' has-error' : '') . '"><legend>' . $e($ctx->label ?? 'Roles') . '</legend>'
            . "\n" . '<input type="hidden" name="' . $name . '" value="">'; // none checked still posts an empty list
        foreach ($roles as $role => $label) {
            $denied = $held !== null && !PermissionCoverage::coversAll($held, $patterns[(string) $role] ?? []);
            $html .= "\n" . '<label class="form-radio"' . ($denied ? ' title="' . $e(self::DENIED) . '"' : '') . '><input type="checkbox" name="' . $name . '" value="' . $e((string) $role) . '"'
                . ($denied ? ' disabled data-rbac-denied' : '')
                . (in_array((string) $role, $selected, true) ? ' checked' : '') . '> ' . $e($label) . '</label>';
        }
        if ($population === null) {
            $html .= "\n" . '<div class="form-help">No population: give the type "users" or the field "population".</div>';
        }
        foreach ($ctx->errors as $error) {
            $html .= "\n" . '<div class="form-error">' . $e((string) $error) . '</div>';
        }
        if ($ctx->help) {
            $html .= "\n" . '<div class="form-help">' . $e($ctx->help) . '</div>';
        }

        return $html . "\n" . '</fieldset>';
    }

    /** @return list<string> */
    public function normalizeInput(mixed $raw, array $config): array
    {
        return RoleList::normalize($raw);
    }

    public function toStorage(mixed $value): mixed
    {
        return RoleList::normalize($value);
    }

    /** @return list<string> */
    public function fromStorage(mixed $value): array
    {
        return RoleList::normalize($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return htmlspecialchars(implode(', ', RoleList::normalize($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }

    /** @return array<string, string> role name => label, by name */
    private function roles(string $population): array
    {
        $roles = [];
        foreach ($this->dm->queryType('rbac_role')->get()->items() as $record) {
            $name = $record->get('name');
            if ($record->get('population') === $population && is_string($name) && trim($name) !== '') {
                $label = $record->get('label');
                $roles[trim($name)] = is_string($label) && $label !== '' ? $label : trim($name);
            }
        }
        ksort($roles);

        return $roles;
    }
}
