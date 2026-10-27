<?php

declare(strict_types=1);

namespace Preflow\Folio\Codes;

use Preflow\Codes\CodeType;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Folio\Extension\Attributes\DeclaresPermission;
use Preflow\Folio\Extension\Attributes\FolioExtension;

/**
 * The codes admin (preflow/codes in Folio), Folio's built-in extension "codes", there when
 * preflow/codes is installed: the code type (the codes core's, CodeType, with what models/code.json
 * adds for the admin: the menu section, list filters, the purpose select, read-only counters),
 * the Redemptions column and panel and the revoke action (Types/CodeAdmin). Its labels are the
 * admin group "codes" (lang/{locale}/codes.php).
 */
#[DeclaresPermission('codes.revoke', 'Revoke codes')]
#[FolioExtension(name: 'codes', label: 'Codes', icon: 'ui-key', version: '1.0.0', translations: 'codes')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    /**
     * The codes core's code type with the admin's additions (models/code.json): its keys added,
     * its fields' settings over the core's, the core's field order.
     */
    public function model(array $schema): array
    {
        if (($schema['key'] ?? null) !== 'code') {
            return $schema;
        }
        $core = CodeType::schema();
        $fields = $core['fields'];
        foreach ((array) ($schema['fields'] ?? []) as $name => $field) {
            $fields[$name] = array_replace((array) ($fields[$name] ?? []), (array) $field);
        }

        unset($core['fields'], $schema['fields']);

        return array_replace($core, $schema) + ['fields' => $fields];
    }

    /** As the core prepares it: storage "codes" when the site has that alias, else "default". */
    public function prepareModel(array $schema, Container $c): array
    {
        if (($schema['key'] ?? null) !== 'code') {
            return $schema;
        }
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        $drivers = is_file($c->get(Application::class)->basePath('config/data.php')) ? $c->get(DataManager::class)->driverNames() : [];

        return CodeType::prepare($schema, $drivers);
    }
}
