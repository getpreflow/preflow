<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Customization;

use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\AfterDelete;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\BulkAction;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\FieldPlaceholder;
use Preflow\Folio\Extension\FormPanel;
use Preflow\Folio\Extension\ListColumn;
use Preflow\Folio\Extension\NavBadge;
use Preflow\Folio\Extension\RecordAction;

/**
 * The enabled type customizations (#[FolioType] parts of enabled extensions), by type key,
 * validated at boot: an unknown type key, a duplicate action name within a type or a bad
 * attribute is an ExtensionException. Several customizations of one type all apply, in
 * extension name order. Their classes are built from the container on first use.
 */
final class Customizations
{
    /** Hook attributes, the names runHooks() takes. */
    public const array HOOKS = [BeforeSave::class, AfterSave::class, BeforeDelete::class, AfterDelete::class];

    /** Action names and name prefixes of Folio's own bulk actions (delete, set-{field}, export). */
    private const array RESERVED_BULK = ['delete', 'export', 'set-'];

    private const string NAME_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    private const string PERMISSION_PATTERN = '/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/';

    private const array METHOD_ATTRIBUTES = [FormPanel::class, RecordAction::class, BulkAction::class, ListColumn::class, NavBadge::class, FieldPlaceholder::class, ...self::HOOKS];

    /**
     * @param array<string, array{panels: list<array{label: string, position: string, extension: string, owner: string, callable: \Closure}>, actions: list<array{name: string, label: string, permission: string, icon: ?string, confirm: ?string, toolbar: bool, group: string, callable: \Closure}>, bulk: list<array{name: string, label: string, permission: string, confirm: ?string, group: string, callable: \Closure}>, columns: list<array{label: string, position: int, callable: \Closure}>, badges: list<\Closure>, hooks: array<class-string, list<\Closure>>, placeholders: array<string, \Closure>}> $types
     */
    /** @var array<string, ?int> */
    private array $badgeCounts = [];

    private function __construct(
        private readonly array $types,
    ) {}

    /**
     * @param \Closure(class-string): object $make builds a customization class (the container)
     * @param ?TypeRegistry $registry when given, a #[FieldPlaceholder] must name a field of the type
     */
    public static function fromExtensions(ExtensionRegistry $extensions, TypeCatalog $catalog, \Closure $make, ?TypeRegistry $registry = null): self
    {
        $types = [];
        $actionOwners = [];
        $bulkOwners = [];
        foreach ($extensions->enabled() as $definition) {
            foreach ($definition->parts as $part) {
                if ($part['kind'] !== 'type' || !isset($part['class']) || !$extensions->partEnabled($definition->name, $part['id'])) {
                    continue;
                }
                $class = $part['class'];
                $reflection = new \ReflectionClass($class);
                $key = self::instance($reflection->getAttributes(FolioType::class)[0] ?? null, $class)?->key;
                if ($key === null) {
                    throw new ExtensionException("{$class} has no #[FolioType] attribute.");
                }
                // The extension's own settings form, switched off with its "settings" part: nothing to customize.
                if ($key === \Preflow\Folio\Extension\Settings\ExtensionSettings::typeKey($definition->name) && !$extensions->partEnabled($definition->name, 'settings')) {
                    continue;
                }
                if (!$catalog->has($key)) {
                    throw new ExtensionException("Type customization {$class}: #[FolioType('{$key}')] names no content type \"{$key}\".");
                }
                $types[$key] ??= ['panels' => [], 'actions' => [], 'bulk' => [], 'columns' => [], 'badges' => [], 'hooks' => [], 'placeholders' => []];

                $badgeMethod = null;
                $built = new \stdClass(); // one instance per class, shared by its methods' closures
                foreach ($reflection->getMethods() as $method) {
                    $name = $method->getName();
                    $where = "{$class}::{$name}";
                    $call = static function (mixed ...$args) use ($built, $make, $class, $name): mixed {
                        $built->instance ??= $make($class);

                        return $built->instance->{$name}(...$args);
                    };
                    foreach (self::METHOD_ATTRIBUTES as $attributeClass) {
                        $attribute = $method->getAttributes($attributeClass)[0] ?? null;
                        if ($attribute === null) {
                            continue;
                        }
                        if (!$method->isPublic() || $method->isStatic()) {
                            throw new ExtensionException("{$where} must be a public, non-static method to carry #[" . self::short($attributeClass) . '].');
                        }
                        $meta = self::instance($attribute, $where);
                        if ($meta instanceof FormPanel) {
                            if (!in_array($meta->position, ['before', 'after', 'side'], true)) {
                                throw new ExtensionException("#[FormPanel] on {$where}: position must be \"before\", \"after\" or \"side\", not \"{$meta->position}\".");
                            }
                            $types[$key]['panels'][] = ['label' => $meta->label, 'position' => $meta->position, 'extension' => $definition->name, 'owner' => $where, 'callable' => $call];
                        } elseif ($meta instanceof RecordAction) {
                            self::checkAction('RecordAction', $where, $meta->name, $meta->permission);
                            if (isset($actionOwners[$key][$meta->name])) {
                                throw new ExtensionException("Record action \"{$meta->name}\" of type \"{$key}\" is declared by both {$actionOwners[$key][$meta->name]} and {$where}.");
                            }
                            $actionOwners[$key][$meta->name] = $where;
                            $types[$key]['actions'][] = [
                                'name' => $meta->name,
                                'label' => $meta->label,
                                'permission' => $meta->permission,
                                'icon' => $meta->icon,
                                'confirm' => $meta->confirm,
                                'toolbar' => $meta->toolbar,
                                'group' => $definition->label,
                                'callable' => $call,
                            ];
                        } elseif ($meta instanceof BulkAction) {
                            self::checkAction('BulkAction', $where, $meta->name, $meta->permission);
                            foreach (self::RESERVED_BULK as $reserved) {
                                if ($meta->name === $reserved || (str_ends_with($reserved, '-') && str_starts_with($meta->name, $reserved))) {
                                    throw new ExtensionException("#[BulkAction] on {$where}: name \"{$meta->name}\" is reserved for Folio's own bulk actions (delete, export, set-{field}).");
                                }
                            }
                            if (isset($bulkOwners[$key][$meta->name])) {
                                throw new ExtensionException("Bulk action \"{$meta->name}\" of type \"{$key}\" is declared by both {$bulkOwners[$key][$meta->name]} and {$where}.");
                            }
                            $bulkOwners[$key][$meta->name] = $where;
                            $types[$key]['bulk'][] = [
                                'name' => $meta->name,
                                'label' => $meta->label,
                                'permission' => $meta->permission,
                                'confirm' => $meta->confirm,
                                'group' => $definition->label,
                                'callable' => $call,
                            ];
                        } elseif ($meta instanceof ListColumn) {
                            $types[$key]['columns'][] = ['label' => $meta->label, 'position' => $meta->position, 'extension' => $definition->name, 'owner' => $where, 'callable' => $call];
                        } elseif ($meta instanceof FieldPlaceholder) {
                            if ($registry !== null && !isset($registry->get($key)->fields[$meta->field])) {
                                throw new ExtensionException("#[FieldPlaceholder] on {$where}: type \"{$key}\" has no field \"{$meta->field}\".");
                            }
                            if (isset($types[$key]['placeholders'][$meta->field])) {
                                throw new ExtensionException("#[FieldPlaceholder] on {$where}: field \"{$meta->field}\" of type \"{$key}\" already has one.");
                            }
                            $types[$key]['placeholders'][$meta->field] = $call;
                        } elseif ($meta instanceof NavBadge) {
                            if ($badgeMethod !== null) {
                                throw new ExtensionException("{$class} has more than one #[NavBadge]: {$badgeMethod} and {$name}.");
                            }
                            $badgeMethod = $name;
                            $types[$key]['badges'][] = $call;
                        } else {
                            $types[$key]['hooks'][$attributeClass][] = $call;
                        }
                    }
                }
            }
        }
        foreach (array_keys($types) as $key) {
            usort($types[$key]['columns'], static fn (array $a, array $b): int => $a['position'] <=> $b['position']); // stable: ties keep extension order
        }

        return new self($types);
    }

    /**
     * @return array{panels: list<array{label: string, position: string, extension: string, owner: string, callable: \Closure}>, actions: list<array{name: string, label: string, permission: string, icon: ?string, confirm: ?string, toolbar: bool, group: string, callable: \Closure}>, bulk: list<array{name: string, label: string, permission: string, confirm: ?string, group: string, callable: \Closure}>, columns: list<array{label: string, position: int, callable: \Closure}>, badges: list<\Closure>, hooks: array<class-string, list<\Closure>>, placeholders: array<string, \Closure>}
     */
    public function forType(string $key): array
    {
        $type = $this->types[$key] ?? ['panels' => [], 'actions' => [], 'bulk' => [], 'columns' => [], 'badges' => [], 'hooks' => [], 'placeholders' => []];
        foreach (['panels', 'actions', 'bulk', 'columns'] as $list) {
            $type[$list] = array_map(self::texts(...), $type[$list]);
        }

        return $type;
    }

    /**
     * An entry with its label and confirm text in the interface language: an attribute may give
     * a translation key instead of a text (FolioText::label()). Resolved on each read, so
     * each request gets its user's language.
     *
     * @template T of array<string, mixed>
     * @param T $entry
     * @return T
     */
    private static function texts(array $entry): array
    {
        $entry['label'] = \Preflow\Folio\FolioText::label((string) $entry['label']);
        if (is_string($entry['confirm'] ?? null)) {
            $entry['confirm'] = \Preflow\Folio\FolioText::label($entry['confirm']);
        }

        return $entry;
    }

    /** The #[FieldPlaceholder] text of a field in $locale, or null (none declared, or the method gives none). */
    public function placeholder(string $type, string $field, string $locale): ?string
    {
        $call = $this->types[$type]['placeholders'][$field] ?? null;
        $text = $call === null ? null : $call($locale);

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * The type's sidebar badge: the sum of its #[NavBadge] methods, null when none or 0. Calls
     * them, so only for a visible item; each type is counted once per request. A callback that
     * throws or returns something other than ?int adds nothing (the admin must render even when
     * an extension's count is broken); callbacks should be cheap, they run on admin page renders.
     */
    public function navBadge(string $key): ?int
    {
        if (array_key_exists($key, $this->badgeCounts)) {
            return $this->badgeCounts[$key];
        }
        $sum = 0;
        foreach ($this->types[$key]['badges'] ?? [] as $call) {
            try {
                $count = $call();
            } catch (\Throwable) {
                continue;
            }
            if (is_int($count) && $count > 0) {
                $sum += $count;
            }
        }

        return $this->badgeCounts[$key] = $sum > 0 ? $sum : null;
    }

    /** @param class-string $hook one of HOOKS (BeforeSave::class, ...) */
    public function runHooks(string $hook, DynamicRecord $record): void
    {
        foreach ($this->types[$record->getType()->key]['hooks'][$hook] ?? [] as $call) {
            $call($record);
        }
    }

    /** @return array{name: string, label: string, permission: string, icon: ?string, confirm: ?string, toolbar: bool, group: string, callable: \Closure}|null */
    public function action(string $type, string $name): ?array
    {
        foreach ($this->types[$type]['actions'] ?? [] as $action) {
            if ($action['name'] === $name) {
                return self::texts($action);
            }
        }

        return null;
    }

    /**
     * The type's #[BulkAction]s, in extension order.
     *
     * @return list<array{name: string, label: string, permission: string, confirm: ?string, group: string, callable: \Closure}>
     */
    public function bulkActions(string $type): array
    {
        return array_map(self::texts(...), $this->types[$type]['bulk'] ?? []);
    }

    /** @return array{name: string, label: string, permission: string, confirm: ?string, group: string, callable: \Closure}|null */
    public function bulkAction(string $type, string $name): ?array
    {
        foreach ($this->bulkActions($type) as $action) {
            if ($action['name'] === $name) {
                return $action;
            }
        }

        return null;
    }

    /** @return array<string, array{label: string, group: string}> the record and bulk actions' permissions (first declaring action wins) */
    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->types as $type) {
            foreach ([...$type['actions'], ...$type['bulk']] as $action) {
                $permissions[$action['permission']] ??= ['label' => $action['label'], 'group' => $action['group']];
            }
        }

        return $permissions;
    }

    private static function checkAction(string $attribute, string $where, string $name, string $permission): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ExtensionException("#[{$attribute}] on {$where}: name \"{$name}\" must match /^[a-z][a-z0-9_-]*$/.");
        }
        if (preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
            throw new ExtensionException("#[{$attribute}] on {$where}: permission \"{$permission}\" must match /^[a-z][a-z0-9_-]*(\\.[a-z0-9_-]+)+$/ (e.g. \"members.approve\").");
        }
    }

    private static function instance(?\ReflectionAttribute $attribute, string $where): ?object
    {
        try {
            return $attribute?->newInstance();
        } catch (\Error $e) {
            throw new ExtensionException('#[' . self::short($attribute->getName()) . "] on {$where}: {$e->getMessage()}", 0, $e);
        }
    }

    private static function short(string $class): string
    {
        return substr($class, strrpos($class, '\\') + 1);
    }
}
