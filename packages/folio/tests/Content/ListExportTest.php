<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ListExport;
use Preflow\Folio\Content\ListQuery;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RelationLabels;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class ListExportTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_export_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        $models = [
            'tag' => ['key' => 'tag', 'table' => 'tag', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['name' => ['type' => 'string']]],
            'item' => ['key' => 'item', 'table' => 'item', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => [
                    'pin' => ['type' => 'string', 'hidden' => true],
                    'title' => ['type' => 'string', 'label' => 'Title'],
                    'qty' => ['type' => 'number', 'label' => 'Quantity'],
                    'due' => ['type' => 'date'],
                    'tags' => ['type' => 'relation', 'relation' => ['to' => 'tag', 'multiple' => true]],
                ]],
        ];
        foreach ($models as $k => $m) {
            file_put_contents($this->base . "/m/$k.json", json_encode($m));
        }
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
        $this->save('tag', ['uuid' => 't1', 'name' => 'red']);
        $this->save('tag', ['uuid' => 't2', 'name' => 'blue']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    /** @param array<string, mixed> $data */
    private function save(string $type, array $data): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get($type), $data), false);
    }

    private function export(?bool $available = null): ListExport
    {
        $types = new FieldTypeRegistry();
        $types->register(new StringFieldType());
        $types->register(new NumberFieldType());
        $types->register(new DateFieldType());

        return new ListExport($this->dm, $types, new RelationLabels($this->dm, new RecordLabeler()), $available);
    }

    /** @return array<string, string> */
    private function columns(): array
    {
        return ['title' => 'Title', 'qty' => 'Quantity', 'due' => 'Due', 'tags' => 'Tags'];
    }

    /** @param array<string, mixed> $params */
    private function query(array $params): ListQuery
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/folio/item')->withQueryParams($params);

        return ListQuery::fromRequest($request, $this->registry->get('item'), ['columns' => array_keys($this->columns())], 'en');
    }

    /** @return list<\OpenSpout\Common\Entity\Row> */
    private function read(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row;
            }
        }
        $reader->close();

        return $rows;
    }

    private function part(string $path, string $name): string
    {
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName($name);
        $zip->close();

        return $xml;
    }

    public function test_available_follows_the_installed_library_unless_overridden(): void
    {
        $this->assertTrue($this->export()->available()); // dev dependency is installed
        $this->assertTrue($this->export(true)->available());
        $this->assertFalse($this->export(false)->available());
    }

    public function test_writes_a_header_row_and_the_records_in_list_order(): void
    {
        $this->save('item', ['uuid' => 'i1', 'title' => 'Beta', 'qty' => '3', 'due' => '2026-10-24', 'tags' => '["t1","t2"]']);
        $this->save('item', ['uuid' => 'i2', 'title' => 'Alpha', 'qty' => '2.5', 'due' => '', 'tags' => 't2']);
        $export = $this->export();
        $path = $this->base . '/out.xlsx';

        $export->write($this->registry->get('item'), $this->columns(), $export->records('item', $this->query(['sort' => 'title'])), $path);

        $rows = $this->read($path);
        $this->assertCount(3, $rows);
        $this->assertSame(['Title', 'Quantity', 'Due', 'Tags'], $rows[0]->toArray());
        $this->assertStringContainsString('<b/>', $this->part($path, 'xl/styles.xml')); // the header's bold font
        $this->assertStringContainsString('s="1"', $this->part($path, 'xl/worksheets/sheet1.xml')); // used by the first row
        $this->assertSame('Alpha', $rows[1]->toArray()[0]);
        $this->assertSame('Beta', $rows[2]->toArray()[0]);
    }

    public function test_default_columns_leave_hidden_fields_out_of_the_file(): void
    {
        $this->save('item', ['uuid' => 'i1', 'pin' => 'pin-secret', 'title' => 'Beta', 'qty' => '3', 'due' => '2026-10-24']);
        $def = $this->registry->get('item');
        $columns = [];
        foreach (ListQuery::columns($def, []) as $name) {
            $columns[$name] = $def->fields[$name]->label ?? ucfirst($name);
        }
        $this->assertArrayNotHasKey('pin', $columns);
        $export = $this->export();
        $path = $this->base . '/out.xlsx';

        $export->write($def, $columns, $export->records('item', $this->query([])), $path);

        $this->assertSame(['Title', 'Quantity', 'Due'], $this->read($path)[0]->toArray());
        $this->assertStringNotContainsString('pin-secret', $this->part($path, 'xl/worksheets/sheet1.xml') . $this->part($path, 'xl/sharedStrings.xml'));
    }

    public function test_numbers_and_dates_are_typed_and_relations_are_labels(): void
    {
        $this->save('item', ['uuid' => 'i1', 'title' => 'Beta', 'qty' => '3', 'due' => '2026-10-24', 'tags' => '["t1","t2"]']);
        $export = $this->export();
        $path = $this->base . '/out.xlsx';

        $export->write($this->registry->get('item'), $this->columns(), $export->records('item', $this->query([])), $path);

        $cells = $this->read($path)[1]->getCells();
        $this->assertInstanceOf(NumericCell::class, $cells[1]);
        $this->assertEquals(3, $cells[1]->getValue());
        $this->assertInstanceOf(\DateTimeInterface::class, $cells[2]->getValue());
        $this->assertSame('2026-10-24', $cells[2]->getValue()->format('Y-m-d'));
        $this->assertSame('red, blue', $cells[3]->getValue());
    }

    public function test_text_that_looks_like_a_formula_stays_text(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd'] as $i => $title) {
            $this->save('item', ['uuid' => "i$i", 'title' => $title]);
        }
        $export = $this->export();
        $path = $this->base . '/out.xlsx';

        $export->write($this->registry->get('item'), $this->columns(), $export->records('item', $this->query(['sort' => 'title'])), $path);

        // The reader guesses formulas from "=" text on its own, so look at what was written: no <f> element.
        $sheet = $this->part($path, 'xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f', $sheet);
        foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd'] as $title) {
            $this->assertStringContainsString($title, $sheet . $this->part($path, 'xl/sharedStrings.xml'));
        }
        $this->assertStringContainsString('t="inlineStr"', $sheet);
    }

    public function test_records_pages_through_everything_in_chunks(): void
    {
        for ($i = 0; $i < ListExport::CHUNK * 2 + 7; $i++) {
            $this->save('item', ['uuid' => sprintf('i%05d', $i), 'title' => sprintf('T%05d', $i)]);
        }

        $titles = [];
        foreach ($this->export()->records('item', $this->query(['sort' => 'title'])) as $record) {
            $titles[] = $record->get('title');
        }

        $this->assertCount(ListExport::CHUNK * 2 + 7, $titles);
        $sorted = $titles;
        sort($sorted);
        $this->assertSame($sorted, $titles);
        $this->assertCount(count($titles), array_unique($titles));
    }

    public function test_records_follow_the_search(): void
    {
        $this->save('item', ['uuid' => 'i1', 'title' => 'Apple']);
        $this->save('item', ['uuid' => 'i2', 'title' => 'Pear']);

        $titles = array_map(static fn ($r) => $r->get('title'), iterator_to_array($this->export()->records('item', $this->query(['q' => 'pea'])), false));

        $this->assertSame(['Pear'], $titles);
    }

    public function test_chunks_over_tied_sort_keys_repeat_and_skip_nothing_on_sqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ticket (uuid TEXT PRIMARY KEY, status TEXT)');
        $total = ListExport::CHUNK * 2 + 37;
        $insert = $pdo->prepare('INSERT INTO ticket VALUES (?, ?)');
        $pdo->beginTransaction();
        for ($i = 0; $i < $total; $i++) {
            $insert->execute([sprintf('t%05d', $i), $i % 7 === 0 ? 'open' : 'done']); // two values, hundreds of ties
        }
        $pdo->commit();
        file_put_contents($this->base . '/m/ticket.json', json_encode([
            'key' => 'ticket', 'table' => 'ticket', 'storage' => 'sqlite', 'id_field' => 'uuid',
            'fields' => ['status' => ['type' => 'string']],
        ]));
        $registry = new TypeRegistry($this->base . '/m');
        $dm = new DataManager(['sqlite' => new \Preflow\Data\Driver\SqliteDriver($pdo)], 'sqlite', $registry, new ValidatorFactory(new RuleFactory()));
        $request = (new Psr17Factory())->createServerRequest('GET', '/folio/ticket')->withQueryParams(['sort' => 'status']);
        $query = ListQuery::fromRequest($request, $registry->get('ticket'), ['columns' => ['status']], 'en');
        $types = new FieldTypeRegistry();
        $types->register(new StringFieldType());
        $export = new ListExport($dm, $types, new RelationLabels($dm, new RecordLabeler()));

        $ids = [];
        foreach ($export->records('ticket', $query) as $record) {
            $ids[] = (string) $record->getId();
        }

        $this->assertCount($total, $ids);
        $this->assertCount($total, array_unique($ids)); // every id exactly once
    }
}
