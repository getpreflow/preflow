<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * Exact comparisons on JSON storage through DataManager: =, !=, IN, NOT IN, range comparisons,
 * ORDER BY and grouping on a type's text fields follow the rule (ignore case, respect accents);
 * exact fields, numbers, dates and driver queries that name no text field compare as before.
 */
final class JsonTextComparisonTest extends TestCase
{
    private string $dir;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/json_text_' . bin2hex(random_bytes(4));
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->dir, $this->dir . '/.cache')], 'json', null, new ValidatorFactory(new RuleFactory()));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function people(?Locales $locales = null, bool $translatable = false): TypeDefinition
    {
        return new TypeDefinition('people', 'people', 'json', [
            'name' => new TypeFieldDefinition('name', 'string', translatable: $translatable),
            'code' => new TypeFieldDefinition('code', 'string', exact: true),
            'n' => new TypeFieldDefinition('n', 'number'),
            'd' => new TypeFieldDefinition('d', 'date'),
        ], idField: 'id', locales: $locales ?? Locales::single('en'));
    }

    /** @param array<string, array<string, mixed>> $rows id => values */
    private function seed(TypeDefinition $def, array $rows): void
    {
        foreach ($rows as $id => $values) {
            $this->dm->saveType(DynamicRecord::fromArray($def, ['id' => $id] + $values), false);
        }
    }

    private function seedPeople(): TypeDefinition
    {
        $def = $this->people();
        $this->seed($def, [
            'p1' => ['name' => 'Müller', 'code' => 'Ab'],
            'p2' => ['name' => 'MÜLLER', 'code' => 'aB'],
            'p3' => ['name' => 'Muller'],
            'p4' => ['name' => 'Straße'],
            'p5' => ['name' => "\u{212A}elvin"],
            'p6' => ['name' => 'İstanbul'],
        ]);

        return $def;
    }

    /**
     * @param \Closure(\Preflow\Data\QueryBuilder): mixed $filter
     * @return list<string>
     */
    private function ids(TypeDefinition $def, \Closure $filter, bool $sorted = true): array
    {
        $builder = $this->dm->queryIn($def);
        $filter($builder);
        $ids = array_map(static fn (DynamicRecord $r): string => (string) $r->get('id'), $builder->get()->items());
        if ($sorted) {
            sort($ids);
        }

        return $ids;
    }

    public function test_equals_and_in_ignore_case_on_text_fields(): void
    {
        $def = $this->seedPeople();

        $this->assertSame(['p1', 'p2'], $this->ids($def, fn ($q) => $q->where('name', 'müller')));
        $this->assertSame(['p3'], $this->ids($def, fn ($q) => $q->where('name', '=', 'muller')));
        $this->assertSame([], $this->ids($def, fn ($q) => $q->where('name', 'strasse')));
        $this->assertSame(['p5'], $this->ids($def, fn ($q) => $q->where('name', 'kelvin')));
        $this->assertSame([], $this->ids($def, fn ($q) => $q->where('name', 'istanbul')));
        $this->assertSame(['p1', 'p2', 'p4'], $this->ids($def, fn ($q) => $q->where('name', 'IN', ['MÜLLER', 'straße'])));
        $this->assertSame(['p1', 'p2'], $this->ids($def, fn ($q) => $q->whereAny([['field' => 'name', 'operator' => '=', 'value' => 'müller']])));
        $this->assertSame(['p1', 'p2', 'p4'], $this->ids($def, fn ($q) => $q->whereAny([['field' => 'name', 'operator' => 'IN', 'value' => ['MÜLLER', 'STRASSE', 'straße']]])));
    }

    public function test_not_equals_and_not_in_follow_the_rule(): void
    {
        $def = $this->seedPeople();
        $this->seed($def, ['p7' => ['name' => null]]);

        $this->assertSame(['p3', 'p4', 'p5', 'p6'], $this->ids($def, fn ($q) => $q->where('name', '!=', 'müller')));
        $this->assertSame(['p3', 'p4', 'p5', 'p6'], $this->ids($def, fn ($q) => $q->where('name', 'NOT IN', ['müller'])));
        $this->assertSame(['p7'], $this->ids($def, fn ($q) => $q->where('name', null)), 'IS NULL as before');
    }

    public function test_range_comparisons_on_text_follow_the_rule(): void
    {
        $def = $this->seedPeople();

        $this->assertSame(['p1', 'p2', 'p3'], $this->ids($def, fn ($q) => $q->where('name', '>=', 'm')->where('name', '<', 'n')));
        $this->assertSame(['p1', 'p2', 'p3'], $this->ids($def, fn ($q) => $q->where('name', '>', 'L')->where('name', '<=', 'MZ')));
    }

    public function test_exact_fields_and_driver_queries_compare_exactly(): void
    {
        $def = $this->seedPeople();

        $this->assertSame([], $this->ids($def, fn ($q) => $q->where('code', 'ab')));
        $this->assertSame(['p1'], $this->ids($def, fn ($q) => $q->where('code', 'Ab')));
        $this->assertSame(['p1'], $this->ids($def, fn ($q) => $q->where('code', 'IN', ['Ab', 'AB'])));

        $driver = $this->dm->driver('json');
        $ids = static fn (Query $q): array => array_map(static fn (array $row): string => (string) $row['id'], $driver->findMany('people', $q)->items());
        $this->assertSame(['p1'], $ids((new Query())->where('name', '=', 'Müller')), 'a driver query that names no text field');
        $this->assertSame([], $ids((new Query())->where('name', '=', 'müller')));
    }

    public function test_text_fields_sort_by_the_rule_numbers_and_dates_by_value(): void
    {
        $def = $this->people();
        $this->seed($def, [
            'o1' => ['name' => 'Zebra', 'n' => 10, 'd' => '2026-10-01'],
            'o2' => ['name' => 'äpfel', 'n' => 2, 'd' => '2026-02-01'],
            'o3' => ['name' => 'Apfel'],
            'o4' => ['name' => 'Émile'],
            'o5' => ['name' => 'eagle'],
        ]);
        $names = fn (\Closure $order, ?\Closure $filter = null): array => array_map(
            static fn (DynamicRecord $r): mixed => $r->get('name'),
            $order($filter === null ? $this->dm->queryIn($def) : $filter($this->dm->queryIn($def)))->get()->items(),
        );

        $this->assertSame(['Apfel', 'äpfel', 'eagle', 'Émile', 'Zebra'], $names(fn ($q) => $q->orderBy('name')));
        $this->assertSame(['Zebra', 'Émile', 'eagle', 'äpfel', 'Apfel'], $names(fn ($q) => $q->orderBy('name', SortDirection::Desc)));
        $this->assertSame(['äpfel', 'Zebra'], $names(fn ($q) => $q->orderBy('n'), fn ($q) => $q->where('n', '>', 0)));
        $this->assertSame(['äpfel', 'Zebra'], $names(fn ($q) => $q->orderBy('d'), fn ($q) => $q->where('d', '!=', null)));

        $this->seed($def, ['t9' => ['name' => '9'], 't10' => ['name' => '10']]);
        $digits = fn ($q) => $q->where('name', 'IN', ['9', '10']);
        $this->assertSame(['10', '9'], $names(fn ($q) => $q->orderBy('name'), $digits), 'digits in a text field sort as text');
        $this->assertSame(['9', '10'], $names(fn ($q) => $q->orderBy('name', numeric: true), $digits));
    }

    public function test_a_translatable_text_field_compares_its_locale_value_by_the_rule(): void
    {
        $def = $this->people(new Locales(['de', 'en'], 'de'), translatable: true);
        $this->seed($def, ['p1' => ['name' => ['de' => 'Müller', 'en' => 'Miller']]]);

        $this->assertSame(['p1'], $this->ids($def, fn ($q) => $q->locale('de')->where('name', 'MÜLLER')));
        $this->assertSame([], $this->ids($def, fn ($q) => $q->locale('en')->where('name', 'MÜLLER')));
        $this->assertSame(['p1'], $this->ids($def, fn ($q) => $q->locale('en')->where('name', 'miller')));
    }

    /** R32: values equal under = form one group; exact fields group exactly. */
    public function test_grouping_and_aggregates_on_text_follow_the_rule(): void
    {
        $def = $this->people();
        $this->seed($def, [
            'g1' => ['name' => 'Über', 'code' => 'X'],
            'g2' => ['name' => 'über', 'code' => 'x'],
            'g3' => ['name' => 'Uber', 'code' => 'x'],
        ]);

        $byName = $this->dm->queryIn($def)->aggregate()->groupBy('name')->count()->orderBy('name')->get();
        $this->assertSame([['name' => 'Uber', 'count' => 1], ['name' => 'Über', 'count' => 2]], $byName);

        $byCode = $this->dm->queryIn($def)->aggregate()->groupBy('code')->count()->orderBy('code')->get();
        $this->assertSame([['code' => 'X', 'count' => 1], ['code' => 'x', 'count' => 2]], $byCode, 'an exact field groups exactly');

        $totals = $this->dm->queryIn($def)->aggregate()
            ->countDistinct('name', 'names')->countDistinct('code', 'codes')
            ->min('name', 'first')->max('name', 'last')
            ->get();
        $this->assertSame([['names' => 2, 'codes' => 2, 'first' => 'Uber', 'last' => 'Über']], $totals);

        $this->assertSame(['g1', 'g2'], $this->ids($def, fn ($q) => $q->whereAny([['field' => 'name', 'operator' => 'HAS', 'value' => ['ÜBER']]])), 'HAS on a text field');
    }

    /** R33: a list field (tags) compares exactly, HAS too, even when its kind is also registered as text. */
    public function test_list_fields_stay_exact(): void
    {
        $this->dm->fieldKinds()->text('tags');
        $this->dm->fieldKinds()->list('tags');
        $def = new TypeDefinition('posts', 'posts', 'json', [
            'title' => new TypeFieldDefinition('title', 'string'),
            'tags' => new TypeFieldDefinition('tags', 'tags'),
        ], idField: 'id');
        $this->seed($def, ['t1' => ['title' => 'One', 'tags' => ['Folio', 'Über']]]);

        $this->assertSame([], $this->ids($def, fn ($q) => $q->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['folio']]])));
        $this->assertSame([], $this->ids($def, fn ($q) => $q->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['ÜBER']]])));
        $this->assertSame(['t1'], $this->ids($def, fn ($q) => $q->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['Folio']]])));
    }

    private function plain(string $uuid, string $name, string $apiKey = ''): void
    {
        $person = new PlainTextPerson();
        $person->uuid = $uuid;
        $person->name = $name;
        $person->apiKey = $apiKey;
        $this->dm->save($person);
    }

    /** @return list<string> */
    private function plainIds(\Closure $filter): array
    {
        return array_map(static fn (PlainTextPerson $p): string => $p->uuid, $filter($this->dm->query(PlainTextPerson::class))->get()->items());
    }

    /** A plain model class (#[Entity] without a type) compares its string fields by the rule too. */
    public function test_a_plain_model_compares_its_text_fields_by_the_rule(): void
    {
        $this->plain('a', 'Zebra', 'Key');
        $this->plain('b', 'äpfel', 'other');
        $this->plain('c', 'Apfel');

        $this->assertSame(['b'], $this->plainIds(fn ($q) => $q->where('name', 'ÄPFEL')));
        $this->assertSame(['c', 'b', 'a'], $this->plainIds(fn ($q) => $q->orderBy('name')));
        $this->assertSame([], $this->plainIds(fn ($q) => $q->where('apiKey', 'key')), 'an exact field stays exact');
        $this->assertSame(['a'], $this->plainIds(fn ($q) => $q->where('apiKey', 'Key')));
    }

    public function test_a_plain_models_unique_check_refuses_a_case_variant(): void
    {
        $this->plain('a', 'MÜLLER', 'Ab');
        $this->plain('c', 'Muller', 'aB');

        try {
            $this->plain('b', 'Müller');
            $this->fail('a case variant of a unique text field is a duplicate');
        } catch (ValidationException $e) {
            $this->assertSame(['name'], array_keys($e->errors()));
        }
        $this->assertSame(['a', 'c'], $this->plainIds(fn ($q) => $q->orderBy('uuid')));
    }
}

#[Entity(table: 'plain_people', storage: 'json')]
final class PlainTextPerson extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(unique: true)]
    public string $name = '';

    #[Field(unique: true, exact: true)]
    public string $apiKey = '';
}
