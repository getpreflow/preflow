<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\RelationFieldType;

final class RelationFieldTypeTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_rel_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Authors',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'nick' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'secret' => ['type' => 'string', 'hidden' => true],
            ],
        ]));
        // The relations to "author" declare which labelField / orderBy the search endpoint may use.
        file_put_contents($this->base . '/m/book.json', json_encode([
            'key' => 'book', 'table' => 'book', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string'],
                'writer' => ['type' => 'relation', 'relation' => ['to' => 'author', 'labelField' => 'nick', 'orderBy' => 'name']],
                'ghost' => ['type' => 'relation', 'relation' => ['to' => 'author', 'labelField' => 'secret', 'orderBy' => 'secret']],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);

        foreach (['a1' => ['Ann', 'Zed', 'zz-hash'], 'a2' => ['Bob', 'Amy', 'aa-hash']] as $id => [$name, $nick, $secret]) {
            $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), [
                'uuid' => $id, 'name' => $name, 'nick' => $nick, 'email' => strtolower($name) . '@example.org', 'secret' => $secret,
            ]));
        }
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function type(): RelationFieldType
    {
        return new RelationFieldType($this->dm, $this->registry);
    }

    private function singleConfig(): array
    {
        return ['relation' => ['to' => 'author', 'multiple' => false]];
    }

    public function test_key(): void
    {
        $this->assertSame('relation', $this->type()->key());
    }

    public function test_a_single_relation_shows_its_record_as_a_row_with_search_below(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'writer', label: 'Writer', value: 'a1', config: $this->singleConfig(), required: true,
        ));
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('<fieldset class="form-group folio-relation" data-folio-relation data-folio-relation-name="writer">', $html);
        $this->assertStringContainsString('<legend>Writer <span class="form-required">*</span></legend>', $html);
        $this->assertMatchesRegularExpression(
            '~<ul class="folio-relation-rows" data-folio-relation-selected><li class="folio-relation-row" data-folio-relation-item>'
            . '<span class="folio-relation-avatar folio-type-icon" data-family="" aria-hidden="true">A</span>'
            . '<span class="folio-relation-text"><span class="folio-relation-label">Ann</span><span class="folio-relation-meta" hidden></span></span>'
            . '<button type="button" class="folio-relation-action" data-folio-relation-remove aria-label="Remove Ann" title="Remove Ann"><svg[^>]*>.*?</svg></button>'
            . '<input type="hidden" name="writer" value="a1"></li></ul>~s',
            $html,
        );
        $this->assertStringNotContainsString('folio-relation-grip', $html, 'one record: nothing to reorder');
        $this->assertMatchesRegularExpression('~<label class="folio-relation-search" data-folio-no-edit><svg[^>]*>.*?</svg><input type="search" data-folio-relation-find placeholder="[^"]*" aria-label="Search Writer" autocomplete="off"></label>~s', $html);
        $this->assertStringNotContainsString('name=""', $html, 'the search box posts nothing');
        // A small target: the options travel with the field, for the search to filter.
        $this->assertStringContainsString('<script type="application/json" data-folio-relation-options>[{"id":"a1","label":"Ann","meta":null},{"id":"a2","label":"Bob","meta":null}]</script>', $html);
        $this->assertStringContainsString('<template data-folio-relation-template><li class="folio-relation-row"', $html);
    }

    public function test_an_empty_single_relation_still_posts_its_name(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'writer', value: '', config: $this->singleConfig()));
        $this->assertStringContainsString('<ul class="folio-relation-rows" data-folio-relation-selected><li hidden><input type="hidden" name="writer" value=""></li></ul>', $html);
    }

    public function test_rows_show_the_meta_field_and_the_targets_tint(): void
    {
        $dir = $this->base . '/catalog';
        mkdir($dir);
        file_put_contents($dir . '/author.json', json_encode(['key' => 'author', 'family' => 'member']));
        $type = new RelationFieldType($this->dm, $this->registry, prefix: '/folio', catalog: new \Preflow\Folio\Content\TypeCatalog($dir));
        $html = $type->renderEditor(new FieldContext(
            name: 'writers', label: 'Writers', value: ['a2', 'a1'],
            config: ['relation' => ['to' => 'author', 'multiple' => true, 'metaField' => 'email']],
        ));

        $this->assertStringContainsString('data-folio-relation-name="writers[]" data-folio-relation-multiple data-folio-relation-open="/folio/author/{id}/edit"', $html);
        $this->assertLessThan(strpos($html, '>Ann<'), strpos($html, '>Bob<'), 'in the linked order');
        $this->assertStringContainsString('<button type="button" class="folio-relation-grip" data-folio-sort-handle aria-label="Move Bob" title="Move Bob">', $html);
        $this->assertStringContainsString('<span class="folio-relation-avatar folio-type-icon" data-family="member" aria-hidden="true">B</span>', $html);
        $this->assertStringContainsString('<span class="folio-relation-label">Bob</span><span class="folio-relation-meta">bob@example.org</span>', $html);
        $this->assertStringContainsString('<a class="folio-relation-action" href="/folio/author/a2/edit" data-folio-relation-open-item aria-label="Open Bob" title="Open Bob">', $html);
        $this->assertStringContainsString('<input type="hidden" name="writers[]" value="a2">', $html);
        $this->assertStringContainsString('"meta":"ann@example.org"', $html);
    }

    public function test_a_hidden_meta_field_is_never_shown(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'writer', value: 'a1', config: ['relation' => ['to' => 'author', 'metaField' => 'secret']]));
        $this->assertStringNotContainsString('hash', $html);
    }

    public function test_initials(): void
    {
        $this->assertSame('DH', RelationFieldType::initials('Dubach Holzbau AG'));
        $this->assertSame('ÜB', RelationFieldType::initials('über-bau'));
        $this->assertSame('?', RelationFieldType::initials('  '));
    }

    public function test_without_access_to_the_target_the_linked_rows_stay_and_nothing_else_is_offered(): void
    {
        $type = new RelationFieldType($this->dm, $this->registry, can: static fn (string $p): bool => false, prefix: '/folio');
        $html = $type->renderEditor(new FieldContext(name: 'writer', value: 'a1', config: $this->singleConfig()));
        $this->assertStringContainsString('<input type="hidden" name="writer" value="a1">', $html);
        $this->assertStringNotContainsString('data-folio-relation-find', $html);
        $this->assertStringNotContainsString('Bob', $html);
        $this->assertStringNotContainsString('data-folio-relation-open', $html);
    }

    private function multi(array $value = [], int $count = 2, array $extra = []): string
    {
        for ($i = 3; $i <= $count; $i++) {
            $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => 'a' . $i, 'name' => 'Person ' . $i]));
        }
        return $this->type()->renderEditor(new FieldContext(
            name: 'writers', label: 'Writers', value: $value,
            config: ['relation' => ['to' => 'author', 'multiple' => true] + $extra],
        ));
    }

    public function test_multiple_escapes_labels_and_values(): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => 'x"1', 'name' => '<b>Eve</b> & "co"']));
        $html = $this->multi(['x"1']);
        $this->assertStringNotContainsString('<b>Eve', $html);
        $this->assertStringContainsString('&lt;b&gt;Eve&lt;/b&gt; &amp; &quot;co&quot;', $html);
        $this->assertStringContainsString('value="x&quot;1"', $html);
        $this->assertStringContainsString('\u003Cb\u003EEve', $html, 'the options JSON cannot close its script');
    }

    public function test_order_by_sorts_options(): void
    {
        $html = $this->multi([], 2, ['orderBy' => 'name']);
        $this->assertLessThan(strpos($html, 'Bob'), strpos($html, 'Ann'));
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => 'a0', 'name' => 'Aaron']));
        $html = $this->multi([], 2, ['orderBy' => 'name']);
        $this->assertLessThan(strpos($html, 'Ann'), strpos($html, 'Aaron'));
    }

    public function test_posted_checkbox_values_store_as_before(): void
    {
        $t = $this->type();
        $posted = ['a2', 'a1'];
        $this->assertSame(['a2', 'a1'], $t->normalizeInput($posted, ['relation' => ['to' => 'author', 'multiple' => true]]));
        $this->assertSame('["a2","a1"]', $t->toStorage($t->normalizeInput($posted, ['relation' => ['to' => 'author', 'multiple' => true]])));
    }

    public function test_normalize_single_and_multiple(): void
    {
        $t = $this->type();
        $this->assertSame('a1', $t->normalizeInput('a1', $this->singleConfig()));
        $this->assertSame(['a1', 'a2'], $t->normalizeInput(['a1', 'a2'], ['relation' => ['to' => 'author', 'multiple' => true]]));
        $this->assertSame([], $t->normalizeInput('x', ['relation' => ['to' => 'author', 'multiple' => true]]));
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $this->assertSame('a1', $t->toStorage('a1'));
        $this->assertSame('a1', $t->fromStorage('a1'));
        $json = $t->toStorage(['a1', 'a2']);
        $this->assertSame(['a1', 'a2'], $t->fromStorage($json));
    }

    public function test_render_frontend_resolves_labels(): void
    {
        $out = $this->type()->renderFrontend('a1', $this->singleConfig());
        $this->assertStringContainsString('Ann', $out);
        $this->assertStringNotContainsString('a1', $out); // shows the label, not the raw id
    }

    public function test_unknown_target_renders_no_search(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'x', config: ['relation' => ['to' => 'ghost', 'multiple' => false]],
        ));
        $this->assertStringContainsString('<input type="hidden" name="x" value="">', $html);
        $this->assertStringNotContainsString('data-folio-relation-find', $html);
        $this->assertStringNotContainsString('Ann', $html);
    }

    public function test_render_frontend_unknown_target_is_empty_not_error(): void
    {
        $out = $this->type()->renderFrontend('someid', ['relation' => ['to' => 'ghost', 'multiple' => false]]);
        $this->assertSame('', $out);
    }

    private function seed(int $n): void
    {
        for ($i = 3; $i <= $n; $i++) {
            $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => 'a' . $i, 'name' => sprintf('Person %03d', $i)]));
        }
    }

    private function remoteType(): RelationFieldType
    {
        return new RelationFieldType($this->dm, $this->registry, prefix: '/folio');
    }

    public function test_inline_limit_is_200(): void
    {
        $this->assertSame(200, RelationFieldType::INLINE_LIMIT);
    }

    public function test_at_the_limit_the_options_travel_with_the_field(): void
    {
        $this->seed(200);
        $html = $this->remoteType()->renderEditor(new FieldContext(name: 'writer', value: 'a1', config: $this->singleConfig()));
        $this->assertStringContainsString('data-folio-relation-options', $html);
        $this->assertStringNotContainsString('data-folio-relation-url', $html);
    }

    public function test_above_the_limit_single_renders_the_linked_row_and_searches_the_endpoint(): void
    {
        $this->seed(201);
        $html = $this->remoteType()->renderEditor(new FieldContext(name: 'writer', label: 'Writer', value: 'a2', config: $this->singleConfig()));
        $this->assertStringNotContainsString('data-folio-relation-options', $html);
        $this->assertStringContainsString('data-folio-relation-url="/folio/_relation/author"', $html);
        $this->assertStringContainsString('<input type="hidden" name="writer" value="a2">', $html);
        $this->assertStringContainsString('<span class="folio-relation-label">Bob</span>', $html);
        $this->assertStringNotContainsString('Person 100', $html);
        $this->assertStringNotContainsString('Ann', $html);
    }

    public function test_above_the_limit_single_without_selection_still_submits_empty(): void
    {
        $this->seed(201);
        $html = $this->remoteType()->renderEditor(new FieldContext(name: 'writer', value: '', config: $this->singleConfig()));
        $this->assertStringContainsString('<input type="hidden" name="writer" value="">', $html);
    }

    public function test_above_the_limit_multiple_renders_selected_rows_with_array_inputs(): void
    {
        $this->seed(201);
        $html = $this->remoteType()->renderEditor(new FieldContext(
            name: 'writers', label: 'Writers', value: ['a1', 'a2'],
            config: ['relation' => ['to' => 'author', 'multiple' => true]],
        ));
        $this->assertStringNotContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('<input type="hidden" name="writers[]" value="a1">', $html);
        $this->assertStringContainsString('<input type="hidden" name="writers[]" value="a2">', $html);
        $this->assertStringContainsString('Ann', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringNotContainsString('Person 150', $html);
        $this->assertStringContainsString('data-folio-relation-find', $html);
    }

    public function test_remote_url_carries_label_order_and_meta_config(): void
    {
        $this->seed(201);
        $html = $this->remoteType()->renderEditor(new FieldContext(
            name: 'writer', config: ['relation' => ['to' => 'author', 'labelField' => 'name', 'orderBy' => 'name', 'metaField' => 'email']],
        ));
        $this->assertStringContainsString('data-folio-relation-url="/folio/_relation/author?label=name&amp;order=name&amp;meta=email"', $html);
    }

    public function test_without_a_prefix_a_large_target_stays_inline(): void
    {
        $this->seed(201);
        $html = $this->type()->renderEditor(new FieldContext(name: 'writer', config: $this->singleConfig()));
        $this->assertStringContainsString('data-folio-relation-options', $html);
    }

    public function test_page_adds_a_declared_meta_field(): void
    {
        file_put_contents($this->base . '/m/shelf.json', json_encode([
            'key' => 'shelf', 'table' => 'shelf', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['owner' => ['type' => 'relation', 'relation' => ['to' => 'author', 'metaField' => 'email']], 'spy' => ['type' => 'relation', 'relation' => ['to' => 'author', 'metaField' => 'secret']]],
        ]));
        $registry = new TypeRegistry($this->base . '/m');
        $type = new RelationFieldType(new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $registry), $registry, prefix: '/folio');
        $this->assertSame([['id' => 'a1', 'label' => 'Ann', 'meta' => 'ann@example.org'], ['id' => 'a2', 'label' => 'Bob', 'meta' => 'bob@example.org']], $type->page('author', null, null, '', 1, 'email')['items']);
        $this->assertSame(['id', 'label'], array_keys($type->page('author', null, null, '', 1, 'secret')['items'][0]), 'a hidden field is never a meta line');
        $this->assertSame(['id', 'label'], array_keys($type->page('author', null, null, '', 1, 'nick')['items'][0]), 'undeclared');
    }

    public function test_page_searches_loosely_and_pages_by_twenty(): void
    {
        $this->seed(45);
        $t = $this->remoteType();
        $all = $t->page('author', null, null, '', 1);
        $this->assertCount(20, $all['items']);
        $this->assertTrue($all['more']);
        $this->assertSame(['id', 'label'], array_keys($all['items'][0]));
        $last = $t->page('author', null, null, '', 3);
        $this->assertCount(5, $last['items']);
        $this->assertFalse($last['more']);
        $hit = $t->page('author', null, null, 'person 04', 1);
        $this->assertSame(['Person 004', 'Person 040', 'Person 041', 'Person 042', 'Person 043', 'Person 044', 'Person 045'], array_column($hit['items'], 'label'));
        $this->assertSame([], $t->page('author', null, null, 'nobody', 1)['items']);
    }

    public function test_page_orders_by_an_existing_field_and_clamps_the_page(): void
    {
        $this->seed(5);
        $t = $this->remoteType();
        $this->assertSame(['Ann', 'Bob', 'Person 003', 'Person 004', 'Person 005'], array_column($t->page('author', null, 'name', '', 1)['items'], 'label'));
        $this->assertSame([], $t->page('author', null, null, '', 999999999999)['items']);
        $this->assertCount(5, $t->page('author', null, null, '', -4)['items']);
    }

    public function test_page_refuses_a_hidden_field_as_label_or_order(): void
    {
        $t = $this->remoteType();
        // "secret" is hidden: even a declared relation naming it does not make it usable.
        $byLabel = $t->page('author', 'secret', null, '', 1)['items'];
        $this->assertSame(['Ann', 'Bob'], array_column($byLabel, 'label'));
        $this->assertStringNotContainsString('hash', (string) json_encode($byLabel));
        // Ordered by the hash, Bob ("aa-hash") would come first.
        $this->assertSame(['Ann', 'Bob'], array_column($t->page('author', null, 'secret', '', 1)['items'], 'label'));
    }

    public function test_page_refuses_an_existing_field_no_relation_declares(): void
    {
        $t = $this->remoteType();
        $this->assertSame(['Ann', 'Bob'], array_column($t->page('author', 'email', null, '', 1)['items'], 'label'));
        // Ordered by nick (undeclared as orderBy), Bob ("Amy") would come first.
        $this->assertSame(['Ann', 'Bob'], array_column($t->page('author', null, 'nick', '', 1)['items'], 'label'));
    }

    public function test_page_accepts_a_declared_label_field_and_order(): void
    {
        $t = $this->remoteType();
        $this->assertSame(['Zed', 'Amy'], array_column($t->page('author', 'nick', 'name', '', 1)['items'], 'label'));
    }

    public function test_page_search_does_not_match_a_hidden_field(): void
    {
        $this->assertSame([], $this->remoteType()->page('author', null, null, 'zz-hash', 1)['items']);
        $this->assertSame([], $this->remoteType()->page('author', null, null, 'hash', 1)['items']);
    }

    public function test_page_order_ends_in_the_id(): void
    {
        // SQLite returns rows in insertion order without an ORDER BY: insert "b" before "a".
        file_put_contents($this->base . '/m/person.json', json_encode([
            'key' => 'person', 'table' => 'person', 'storage' => 'sqlite', 'id_field' => 'uuid',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->base . '/m/team.json', json_encode([
            'key' => 'team', 'table' => 'team', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['lead' => ['type' => 'relation', 'relation' => ['to' => 'person', 'orderBy' => 'name']]],
        ]));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE person (uuid TEXT PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO person VALUES ('p3', 'Same'), ('p1', 'Same'), ('p2', 'Same'), ('p0', 'Aaa')");
        $registry = new TypeRegistry($this->base . '/m');
        $dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s'), 'sqlite' => new \Preflow\Data\Driver\SqliteDriver($pdo)], 'json', $registry);
        $t = new RelationFieldType($dm, $registry, prefix: '/folio');

        $this->assertSame(['p0', 'p1', 'p2', 'p3'], array_column($t->page('person', null, 'name', '', 1)['items'], 'id'));
        $this->assertSame(['p0', 'p1', 'p2', 'p3'], array_column($t->page('person', null, null, '', 1)['items'], 'id'));
    }
}
