<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Data\TypeAction;
use Preflow\Data\TypeDefinition;

/**
 * The permissions of a content type: "{type}.view", ".create", ".edit", ".publish" and ".delete",
 * what each verb does to a record (data's TypeAction) and their declaration. One set of names for
 * every adapter: Folio's screens and a site's API controller ask the same question, allows(),
 * which needs the user's permission and the type's own answer (data's "readonly": true,
 * "creatable": false). rbac declares them for every type at boot that nobody declared; an admin
 * declares them with its own labels (Folio's catalog labels), which replace rbac's.
 *
 * @since 0.93.0
 */
final class TypePermissions
{
    /** The record verbs and the data action each one is; "view" (and any other verb) is never restricted by the type. */
    public const array VERBS = [
        'create' => TypeAction::Create,
        'edit' => TypeAction::Update,
        'publish' => TypeAction::Update,
        'delete' => TypeAction::Delete,
    ];

    /** The verbs of the five names and their labels, in the order a role editor lists them. */
    private const array LABELS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'];

    /** The permission name of a verb of a type: name('job', 'publish') is "job.publish". */
    public static function name(string $type, string $verb): string
    {
        return $type . '.' . $verb;
    }

    /**
     * Whether the signed-in user may do $verb with records of the type: their permission
     * "{type}.{verb}" (Access::can()) and, for create, edit, publish and delete, the type's own
     * answer (TypeDefinition::allows()); a read-only type refuses edit even to "*".
     */
    public static function allows(Access $access, TypeDefinition $def, string $verb): bool
    {
        $action = self::VERBS[$verb] ?? null;

        return ($action === null || $def->allows($action)) && $access->can(self::name($def->key, $verb));
    }

    /**
     * Declares the family "{type}.*" and its five names, labelled View, Create, Edit, Publish and
     * Delete in the group $label (the type's name for people). A second declaration replaces the
     * labels: the admin that declares last names them.
     */
    public static function declare(PermissionRegistry $registry, string $type, string $label): void
    {
        $pattern = $type . '.*';
        if (!in_array($pattern, $registry->patterns(), true)) {
            $registry->declarePattern($pattern);
        }
        foreach (self::LABELS as $verb => $verbLabel) {
            $registry->declare(self::name($type, $verb), $verbLabel, $label);
        }
    }
}
