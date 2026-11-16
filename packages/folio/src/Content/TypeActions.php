<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\TypeAction;
use Preflow\Data\TypeRegistry;

/**
 * What the admin may do with a type's records, as the type's model definition says (data's
 * TypeDefinition::allows(): "readonly": true, "creatable": false). Folio owns nothing of it: it
 * maps its verbs onto data's actions so every screen, button and route asks the core the same
 * question. Folio's "edit" and "publish" change a record (Update), "create" makes one (Create),
 * "delete" removes it (Delete); "view" and every other verb are never restricted by the type.
 *
 * @since 0.78
 */
final class TypeActions
{
    /** Folio's record verbs and the data action each one is. */
    public const array VERBS = [
        'create' => TypeAction::Create,
        'edit' => TypeAction::Update,
        'publish' => TypeAction::Update,
        'delete' => TypeAction::Delete,
    ];

    public function __construct(private readonly TypeRegistry $types) {}

    /** Whether the type allows the action; a key that is no data type (assets, folio) allows everything. */
    public function allows(string $type, TypeAction $action): bool
    {
        return !$this->types->has($type) || $this->types->get($type)->allows($action);
    }

    /** Whether the type allows the record verb ("create", "edit", "publish", "delete"; any other: true). */
    public function allowsVerb(string $type, string $verb): bool
    {
        $action = self::VERBS[$verb] ?? null;

        return $action === null || $this->allows($type, $action);
    }

    /**
     * Whether a permission name "{type}.{verb}" names something the type allows at all; names of
     * other shapes ("folio.dashboard", "assets.edit", "ai.translate") pass. Asked next to the
     * user's permission: both must say yes.
     */
    public function permits(string $permission): bool
    {
        $dot = strrpos($permission, '.');

        return $dot === false || $this->allowsVerb(substr($permission, 0, $dot), substr($permission, $dot + 1));
    }

    /** Whether the type is read-only (lists and views only). */
    public function readonly(string $type): bool
    {
        return $this->types->has($type) && $this->types->get($type)->readonly;
    }
}
