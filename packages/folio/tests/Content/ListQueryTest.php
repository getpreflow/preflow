<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ListQuery;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class ListQueryTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    /** @var array<string, mixed> */
    private array $config = [
        'columns' => ['title', 'kind', 'num', 'tags'],
        'filters' => ['kind', 'done', 'due', 'tags', 'owner'],
    ];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_listquery_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        $models = [
            'tag' => ['key' => 'tag', 'table' => 'tag', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['name' => ['type' => 'string']]],
            'item' => ['key' => 'item', 'table' => 'item', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => [
                    'title' => ['type' => 'string'],
                    'kind' => ['type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
                    'done' => ['type' => 'boolean'],
                    'due' => ['type' => 'date'],
                    'num' => ['type' => 'number'],
                    'notes' => ['type' => 'text'],
                    'tags' => ['type' => 'relation', 'relation' => ['to' => 'tag', 'multiple' => true]],
                    'owner' => ['type' => 'relation', 'relation' => ['to' => 'tag']],
                ]],
            'slide' => ['key' => 'slide', 'table' => 'slide', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['title' => ['type' => 'string']]],
        ];
        foreach ($models as $k => $m) {
            file_put_contents($this->base . "/m/$k.json", json_encode($m));
        }
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));

        $this->save('tag', ['uuid' => 't1', 'name' => 'red']);
        $this->save('tag', ['uuid' => 't2', 'name' => 'blue']);
        $rows = [
            ['uuid' => 'i1', 'title' => 'Alpha', 'kind' => 'a', 'done' => true, 'due' => '2026-01-10', 'num' => '9', 'notes' => 'zzz', 'tags' => json_encode(['t1']), 'owner' => 't1'],
            ['uuid' => 'i2', 'title' => 'Beta', 'kind' => 'b', 'done' => false, 'due' => '2026-03-05T10:00', 'num' => '10', 'notes' => '', 'tags' => json_encode(['t1', 't2']), 'owner' => 't2'],
            ['uuid' => 'i3', 'title' => 'Gamma', 'kind' => 'a', 'done' => true, 'due' => '2026-06-01', 'num' => '2', 'notes' => '', 'tags' => json_encode(['t2']), 'owner' => ''],
        ];
        foreach ($rows as $row) {
            $this->save('item', $row);
        }
        foreach (['s1' => 20, 's2' => 10, 's3' => 30] as $id => $pos) {
            $this->save('slide', ['uuid' => $id, 'title' => $id, '_order' => $pos]);
        }
    }

    /** @param array<string, mixed> $row */
    private function save(string $type, array $row): void
    {
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get($type), $row), false);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->base);
    }

    /** @param array<string, mixed> $params */
    private function lq(array $params, string $type = 'item', ?array $config = null): ListQuery
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/' . $type)->withQueryParams($params);

        return ListQuery::fromRequest($req, $this->registry->get($type), $config ?? $this->config, 'en');
    }

    /** @return list<string> */
    private function ids(ListQuery $lq, string $type = 'item'): array
    {
        $result = $lq->apply($this->dm->queryType($type))->get();

        return array_map(static fn ($r) => $r->get('uuid'), iterator_to_array($result));
    }

    public function test_loose_search(): void
    {
        $this->assertSame(['i1'], $this->ids($this->lq(['q' => 'alp'])));
    }

    public function test_select_filter(): void
    {
        $this->assertEqualsCanonicalizing(['i1', 'i3'], $this->ids($this->lq(['f' => ['kind' => 'a']])));
    }

    public function test_boolean_filter(): void
    {
        $this->assertEqualsCanonicalizing(['i1', 'i3'], $this->ids($this->lq(['f' => ['done' => '1']])));
        $this->assertSame(['i2'], $this->ids($this->lq(['f' => ['done' => '0']])));
    }

    public function test_relation_filters(): void
    {
        $this->assertEqualsCanonicalizing(['i1', 'i2'], $this->ids($this->lq(['f' => ['tags' => 't1']])));
        $this->assertSame(['i2'], $this->ids($this->lq(['f' => ['owner' => 't2']])));
    }

    public function test_date_range_filter(): void
    {
        $this->assertSame(['i2'], $this->ids($this->lq(['f' => ['due' => ['from' => '2026-02-01', 'to' => '2026-03-05']]])));
        $this->assertEqualsCanonicalizing(['i2', 'i3'], $this->ids($this->lq(['f' => ['due' => ['from' => '2026-02-01']]])));
        $this->assertSame(['i1'], $this->ids($this->lq(['f' => ['due' => ['to' => '2026-01-10']]])));
    }

    public function test_filter_outside_list_filters_is_ignored(): void
    {
        $lq = $this->lq(['f' => ['title' => 'Alpha', 'notes' => 'zzz', 'nope' => 'x', 'bad"field' => 'x']]);
        $this->assertSame([], $lq->filters);
        $this->assertCount(3, $this->ids($lq));
    }

    public function test_sort_numeric_and_descending(): void
    {
        $this->assertSame(['i3', 'i1', 'i2'], $this->ids($this->lq(['sort' => 'num'])));
        $this->assertSame(['i2', 'i1', 'i3'], $this->ids($this->lq(['sort' => '-num'])));
        $lq = $this->lq(['sort' => '-num']);
        $this->assertSame('num', $lq->sort);
        $this->assertTrue($lq->desc);
    }

    public function test_sort_on_unknown_hidden_or_relation_field_is_ignored(): void
    {
        foreach (['nope', 'notes', 'tags', 'title"; DROP', 'due'] as $field) {
            $this->assertNull($this->lq(['sort' => $field])->sort, $field);
            $this->assertNull($this->lq(['sort' => '-' . $field])->sort, $field);
        }
    }

    public function test_default_list_columns_apply_when_none_configured(): void
    {
        $this->assertSame(['title', 'kind', 'done'], ListQuery::columns($this->registry->get('item'), []));
        $this->assertSame('kind', $this->lq(['sort' => 'kind'], 'item', [])->sort);
        $this->assertNull($this->lq(['sort' => 'num'], 'item', [])->sort);
    }

    public function test_page_is_clamped_low_and_high(): void
    {
        $this->assertSame(1, $this->lq(['page' => '-3'])->page);
        $this->assertSame(1, $this->lq(['page' => 'abc'])->page);
        $this->assertSame(1, $this->lq(['page' => ['x']])->page);

        $result = $this->lq(['page' => '999'])->fetch($this->dm->queryType('item'));
        $this->assertSame(1, $result->currentPage);
        $this->assertCount(3, $result->items);
    }

    public function test_orderable_type_keeps_manual_order_until_sorted(): void
    {
        $config = ['orderable' => true, 'columns' => ['title']];
        $this->assertSame(['s2', 's1', 's3'], $this->ids($this->lq([], 'slide', $config), 'slide'));
        $this->assertSame(['s3', 's2', 's1'], $this->ids($this->lq(['sort' => '-title'], 'slide', $config), 'slide'));
    }

    public function test_query_round_trips(): void
    {
        $params = ['q' => 'a b', 'f' => ['kind' => 'a', 'due' => ['from' => '2026-01-01', 'to' => '2026-12-31']], 'sort' => '-num', 'page' => '1'];
        $lq = $this->lq($params);
        $again = $this->lq($lq->query());
        $this->assertEquals($lq->query(), $again->query());
        $this->assertSame('a b', $lq->query()['q']);
        $this->assertSame('-num', $lq->query()['sort']);
        $this->assertSame([], $this->lq([])->query());
    }
}
