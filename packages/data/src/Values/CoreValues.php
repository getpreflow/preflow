<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\FieldKinds;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Values\Core\AssetValue;
use Preflow\Data\Values\Core\BooleanValue;
use Preflow\Data\Values\Core\DateValue;
use Preflow\Data\Values\Core\NumberValue;
use Preflow\Data\Values\Core\RelationValue;
use Preflow\Data\Values\Core\RichTextValue;
use Preflow\Data\Values\Core\SelectValue;
use Preflow\Data\Values\Core\TagsValue;
use Preflow\Data\Values\Core\TextValue;

/**
 * The core field types' values (0.93): string, text, number, boolean, date, select, tags,
 * relation and asset (the media field's key), and richtext when register() is given a cleaner,
 * registered on every DataManager's FieldKinds
 * (DataManager::fieldKinds()), so a site without Folio or any other package reads and writes
 * them the same way. Packages register their own types' values in their service provider,
 * replacing a core one where they bring the type (FieldKinds::value()).
 *
 * register() also makes tags a list kind (FieldKinds::list()), and gives date its stored rule (StoredDate, on FieldKinds::rules()): every write
 * refuses a date the type cannot read, with or without Folio.
 *
 * @since 0.93.0
 */
final class CoreValues
{
    /**
     * The core output shapes, each with what it is (the description's "values"); frozen in version 1.
     * A value reads as null where its text says so: without a value, and for a stored value outside
     * the shape (older data, a row a script wrote): the stored value stays as it is.
     */
    public const SHAPES = [
        'string' => 'Text, as a string, or null.',
        'number' => 'A number: an integer, or a decimal number; or null.',
        'boolean' => 'Yes or no, as true or false; or null when not set.',
        'date' => 'A date as stored: "Y-m-d", "Y-m-d\TH:i" (optionally with seconds) or ISO 8601 with an offset; or null.',
        'rich_text' => 'HTML, cleaned of scripts, event handlers and unsafe links: safe to output as it is; or null.',
        'id' => 'The id of one record, as a string, or null when none.',
        'id_list' => 'A list of record ids, each a string, or null when none.',
        'string_list' => 'A list of strings; [] when none.',
        'raw' => 'The value as it is stored, unchanged.',
    ];

    /**
     * @param (\Closure(): HtmlCleaner)|null $cleaner the cleaner richtext uses, asked on each clean
     *        (DataManager::fieldKinds() passes its htmlCleaner()); null registers no richtext value
     */
    public static function register(FieldKinds $kinds, ?\Closure $cleaner = null): void
    {
        $text = new TextValue();
        $date = new DateValue();
        $kinds->value('string', $text);
        $kinds->value('text', $text);
        $kinds->value('number', new NumberValue());
        $kinds->value('boolean', new BooleanValue());
        $kinds->value('date', $date);
        $kinds->value('select', new SelectValue());
        $kinds->value('tags', new TagsValue());
        // Its value is always a list (since 0.93 the core's say, as Folio's since 0.78): written as one
        // (a comma string or JSON text becomes a list), no text index or text matching on its column.
        $kinds->list('tags');
        $kinds->value('relation', new RelationValue());
        $kinds->value('asset', new AssetValue());
        // A media field with "asset.multiple" stores a list of ids (Folio's since 0.89, the core's since 0.93).
        $kinds->listWhen('asset', static fn (array $config): bool => AssetValue::multiple($config));
        if ($cleaner !== null) {
            $kinds->value('richtext', new RichTextValue($cleaner));
        }
        // The stored shapes date reads (0.92.1's rule, the core's since 0.93): Folio leaves a kind
        // with rules in place (FieldKinds::hasRules()), and its form runs its narrower input rule.
        $kinds->rules('date', static fn (array $config): array => $date->storedRules($config));
    }

    /**
     * The entries of SHAPES for $names.
     *
     * @param string ...$names shape names
     * @return array<string, string>
     */
    public static function shapes(string ...$names): array
    {
        return array_intersect_key(self::SHAPES, array_flip($names));
    }
}
