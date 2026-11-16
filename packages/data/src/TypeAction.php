<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * What an editor or an API may do with a type's records (TypeDefinition::allows()): the type's
 * model definition decides ("readonly": true, "creatable": false), every adapter asks the same
 * question. Reading is never restricted. Code (seeders, migrations, DataManager from the app)
 * writes regardless: the type's actions are about editors and adapters, not storage. Machine
 * translation is an adapter (it writes for editors): a type that refuses Update is not translated.
 *
 * @since 0.78
 */
enum TypeAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
