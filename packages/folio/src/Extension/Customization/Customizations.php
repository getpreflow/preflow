<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Customization;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\AfterDelete;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
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

    private const array METHOD_ATTRIBUTES = [FormPanel::class, RecordAction::class, ListColumn::class, NavBadge::class, ...self::HOOKS];

    /**
     * @param array<string, array{panels: list<array{label: string, position: string, callable: \Closure}>, actions: list<array{name: string, label: string, permission: string, icon: ?string, confirm: ?string, group: string, callable: \Closure}>, columns: list<array{label: string, position: int, callable: \Closure}>, badges: list<\Closure>, hooks: array<class-string, list<\Closure>>}> $types
     */
    /** @var array<string, ?int> */
    private array $badgeCounts = [];

    private function __construct(
        private readonly array $types,
    ) {}

    /** @param \Closure(class-string): object $make builds a customization class (the container) */
    public static function fromExtensions(ExtensionRegistry $extensions, TypeCatalog $catalog, \Closure $make): self
    {
        $types = [];
        $actionOwners = [];
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
                if (!$catalog->has($key)) {
                    throw new ExtensionException("Type customization {$class}: #[FolioType('{$key}')] names no content type \"{$key}\".");
                }
                $types[$key] ??= ['panels' => [], 'actions' => [], 'columns' => [], 'badges' => [], 'hooks' => []];

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
                            if (!in_array($meta->position, ['before', 'after'], true)) {
                                throw new ExtensionException("#[FormPanel] on {$where}: position must be \"before\" or \"after\", not \"{$meta->position}\".");
                            }
                            $types[$key]['panels'][] = ['label' => $meta->label, 'position' => $meta->position, 'callable' => $call];
                        } elseif ($meta instanceof RecordAction) {
                            if (preg_match('/^[a-z][a-z0-9_-]*$/', $meta->name) !== 1) {
                                throw new ExtensionException("#[RecordAction] on {$where}: name \"{$meta->name}\" must match /^[a-z][a-z0-9_-]*$/.");
                            }
                            if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/', $meta->permission) !== 1) {
                                throw new ExtensionException("#[RecordAction] on {$where}: permission \"{$meta->permission}\" must match /^[a-z][a-z0-9_-]*(\\.[a-z0-9_-]+)+$/ (e.g. \"members.approve\").");
                            }
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
                                'group' => $definition->label,
                                'callable' => $call,
                            ];
                        } elseif ($meta instanceof ListColumn) {
                            $types[$key]['columns'][] = ['label' => $meta->label, 'position' => $meta->position, 'callable' => $call];
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
     * @return array{panels: list<array{label: string, position: string, callable: \Closure}>, actions: list<array{name: string, label: string, permission: string, icon: ?string, confirm: ?string, group: string, callable: \Closure}>, columns: list<array{label: string, position: int, callable: \Closure}>, badges: list<\Closure>, hooks: array<class-string, list<\Closure>>}
     */
    public function forType(string $key): array
    {
        return $this->types[$key] ?? ['panels' => [], 'actions' => [], 'columns' => [], 'badges' => [], 'hooks' => []];
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

    /** @return array{name: string, label: string, permission: string, icon: ?string, confirm: ?string, group: string, callable: \Closure}|null */
    public function action(string $type, string $name): ?array
    {
        foreach ($this->types[$type]['actions'] ?? [] as $action) {
            if ($action['name'] === $name) {
                return $action;
            }
        }

        return null;
    }

    /** @return array<string, array{label: string, group: string}> the record actions' permissions (first declaring action wins) */
    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->types as $type) {
            foreach ($type['actions'] as $action) {
                $permissions[$action['permission']] ??= ['label' => $action['label'], 'group' => $action['group']];
            }
        }

        return $permissions;
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
