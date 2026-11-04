<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The addresses records last had on the public site (folio_addresses): one row per record and
 * locale, its path in RedirectPath's form. Compared after every change to write the automatic
 * redirect entries.
 */
final class AddressTable
{
    public const string TABLE = 'folio_addresses';

    private static ?TypeDefinition $definition = null;

    public static function definition(): TypeDefinition
    {
        return self::$definition ??= new TypeDefinition(
            key: '_addresses',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'type' => new TypeFieldDefinition('type', 'string'),
                'record_id' => new TypeFieldDefinition('record_id', 'string'),
                'locale' => new TypeFieldDefinition('locale', 'string'),
                'path' => new TypeFieldDefinition('path', 'string'),
            ],
            idField: 'id',
        );
    }
}
