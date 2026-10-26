<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * An admin list as an .xlsx file (OpenSpout, optional: "openspout/openspout" in the app's
 * composer.json). Rows are streamed, so memory stays flat: records are read in chunks of
 * CHUNK and written as they come. The header row is bold; number and date fields are typed
 * cells; everything else is the list's plain-text cell, written as text (never a formula).
 */
final class ListExport
{
    public const int CHUNK = 500;

    private const array NUMERIC_TYPES = ['number', 'int', 'integer', 'float'];

    /** @param ?bool $available forces the answer of available() (tests); null asks whether OpenSpout is installed */
    public function __construct(
        private readonly DataManager $dm,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly RelationLabels $relations,
        private readonly ?bool $available = null,
        private readonly ?DisplayLabels $labels = null,
    ) {}

    public function available(): bool
    {
        return $this->available ?? class_exists(Writer::class);
    }

    /**
     * Every record the list query matches (search, filters, order; not its page), read in chunks.
     *
     * @return \Generator<int, DynamicRecord>
     */
    public function records(string $type, ListQuery $query): \Generator
    {
        for ($offset = 0;; $offset += self::CHUNK) {
            $qb = $query->apply($this->dm->queryType($type));
            $items = array_values($qb->limit(self::CHUNK)->offset($offset)->get()->items());
            yield from $items;
            if (count($items) < self::CHUNK) {
                return;
            }
        }
    }

    /**
     * @param array<string, string>   $columns field => header label
     * @param iterable<DynamicRecord> $records
     */
    public function write(TypeDefinition $def, array $columns, iterable $records, string $path): void
    {
        $relationFields = array_values(array_filter(array_keys($columns), static fn (string $name) => $def->fields[$name]->type === 'relation'));

        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row(
                array_map(static fn (string $label) => new StringCell($label, null), array_values($columns)),
                (new Style())->setFontBold(),
            ));

            $chunk = [];
            foreach ($records as $record) {
                $chunk[] = $record;
                if (count($chunk) === self::CHUNK) {
                    $this->flush($writer, $def, $columns, $relationFields, $chunk);
                    $chunk = [];
                }
            }
            $this->flush($writer, $def, $columns, $relationFields, $chunk);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param array<string, string> $columns
     * @param list<string>          $relationFields
     * @param list<DynamicRecord>   $chunk
     */
    private function flush(Writer $writer, TypeDefinition $def, array $columns, array $relationFields, array $chunk): void
    {
        if ($chunk === []) {
            return;
        }
        $labels = $relationFields === [] ? [] : $this->relations->forRecords($def, $relationFields, $chunk);
        foreach ($chunk as $record) {
            $cells = [];
            foreach (array_keys($columns) as $name) {
                $cells[] = $this->cell($def, $name, $record, $labels);
            }
            $writer->addRow(new Row($cells));
        }
    }

    /** @param array<string, array<string, string>> $labels */
    private function cell(TypeDefinition $def, string $name, DynamicRecord $record, array $labels): Cell
    {
        $type = $def->fields[$name]->type;
        $raw = $record->localized($def->locales->default)->get($name);

        if (in_array($type, self::NUMERIC_TYPES, true) && is_numeric($raw)) {
            return Cell::fromValue($raw + 0);
        }
        if ($type === 'date' && is_string($raw) && ($date = self::date($raw)) !== null) {
            return Cell::fromValue($date[0], (new Style())->setFormat($date[1]));
        }

        // Text is always a string cell: Cell::fromValue() would turn "=1+1" into a formula.
        return new StringCell(ListCells::text($this->fieldTypes, $def, $name, $record, $labels, $this->labels), null);
    }

    /** @return array{\DateTimeImmutable, string}|null the date and its number format; null: not a date this reads */
    private static function date(string $raw): ?array
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        foreach ([['Y-m-d|', 'Y-m-d', 'yyyy-mm-dd'], ['Y-m-d\TH:i', 'Y-m-d\TH:i', 'yyyy-mm-dd hh:mm']] as [$read, $check, $format]) {
            $date = \DateTimeImmutable::createFromFormat($read, $raw, $zone);
            if ($date !== false && $date->format($check) === $raw) {
                return [$date, $format];
            }
        }

        return null;
    }
}
