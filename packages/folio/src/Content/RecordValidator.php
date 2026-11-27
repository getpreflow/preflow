<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\ValidationException;

/**
 * Full validation of a record: the data layer's (DataManager::validate()), which since 0.92 also
 * checks the values inside block lists and page areas (preflow/blocks' BlockValueChecks) and has
 * Folio's field types' rules (CoreFieldRules, registered by FolioServiceProvider at boot; code
 * that builds a validator by hand registers them itself), so this walks no blocks and merges no
 * field-type rules itself. It gives each field the core's rules (DataManager::fieldRules()) plus Folio's own:
 * with a frontend resolver, the fields a type's public address is built from also get "slug"
 * ("slug:path" for a catch-all): no umlauts, capitals or spaces. A "readonly" field gets an empty
 * list: it is not validated on save, its blocks included (nor checked for being unique).
 */
final class RecordValidator
{
    /** Since 0.92 without the FieldTypeRegistry: the block walk and the field types' rules are the core's. */
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?FrontendResolver $frontend = null,
    ) {}

    /** @throws ValidationException block errors and the fields' errors in one refusal */
    public function validate(DynamicRecord $record): void
    {
        $this->dm->validate($record, $this->rules($record->getType()));
    }

    /**
     * Per-field rules: the core's for the field (its "validate", its type's checks, a select's
     * options, the rules packages add, a bounded column's length) and, for a field of the type's
     * frontend route, "slug" or "slug:path" (a catch-all). Keyed by field, because the data layer
     * takes a given list instead of its own. A "readonly" field gets an empty list: it is not
     * validated on save (nor checked for being unique, nor its blocks).
     *
     * @return array<string, list<string|\Preflow\Validation\ValidationRule>>
     */
    public function rules(TypeDefinition $typeDef): array
    {
        $rules = [];
        $core = $this->dm->fieldRules($typeDef);
        $addressRules = $this->addressRules($typeDef);
        foreach ($typeDef->fields as $name => $fieldDef) {
            if (self::readonly($fieldDef->config)) {
                // The editor can't touch it, so its stored value is not checked: it would block
                // every save (and "required" can't be met). An empty list replaces the type's own rules.
                $rules[$name] = [];
                continue;
            }
            $merged = $core[$name] ?? [];
            if (isset($addressRules[$name])) {
                $merged[] = $addressRules[$name];
            }
            if ($merged !== []) {
                $rules[$name] = $merged;
            }
        }

        return $rules;
    }
    /**
     * "slug" for each parameter of the type's route, "slug:path" for a catch-all.
     *
     * @return array<string, AddressPart>
     */
    private function addressRules(TypeDefinition $typeDef): array
    {
        $route = $this->frontend?->routeFor($typeDef->key);
        if ($route === null) {
            return [];
        }
        return array_map(static fn (string $mode): AddressPart => new AddressPart($typeDef->locales, $mode === 'path'), $route->addressModes());
    }

    /** @param array<string, mixed> $config */
    private static function readonly(array $config): bool
    {
        return ($config['readonly'] ?? false) === true;
    }
}
