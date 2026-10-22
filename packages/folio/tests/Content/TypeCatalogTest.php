<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;

final class TypeCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_models_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    public function test_discovers_valid_types_with_label(): void
    {
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'label' => 'Pages', 'fields' => [],
        ]));
        file_put_contents($this->dir . '/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'fields' => [],
        ]));

        $catalog = new TypeCatalog($this->dir);
        $all = $catalog->all();

        $keys = array_map(fn ($t) => $t->key, $all);
        sort($keys);
        $this->assertSame(['author', 'page'], $keys);

        $byKey = [];
        foreach ($all as $t) {
            $byKey[$t->key] = $t->label;
        }
        $this->assertSame('Pages', $byKey['page']);     // explicit label
        $this->assertSame('Author', $byKey['author']);  // derived from key (ucfirst)
    }

    public function test_ignores_malformed_json(): void
    {
        file_put_contents($this->dir . '/good.json', json_encode(['key' => 'good', 'table' => 'good', 'fields' => []]));
        file_put_contents($this->dir . '/broken.json', '{ not valid json');

        $catalog = new TypeCatalog($this->dir);

        $this->assertCount(1, $catalog->all());
        $this->assertTrue($catalog->has('good'));
        $this->assertFalse($catalog->has('broken'));
    }

    public function test_missing_dir_yields_empty(): void
    {
        $catalog = new TypeCatalog($this->dir . '/nope');
        $this->assertSame([], $catalog->all());
    }

    public function test_scan_is_memoized(): void
    {
        $dir = sys_get_temp_dir() . '/tc_memo_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.json', json_encode(['key' => 'a', 'table' => 'a', 'fields' => []]));
        $catalog = new \Preflow\Folio\Content\TypeCatalog($dir);

        $this->assertCount(1, $catalog->all());
        file_put_contents($dir . '/b.json', json_encode(['key' => 'b', 'table' => 'b', 'fields' => []]));
        $this->assertCount(1, $catalog->all()); // one scan per catalog (i.e. per request)

        @unlink($dir . '/a.json');
        @unlink($dir . '/b.json');
        @rmdir($dir);
    }

    private function model(array $extra): void
    {
        file_put_contents($this->dir . '/member.json', json_encode(['key' => 'member', 'table' => 'member', 'fields' => []] + $extra));
    }

    public function test_user_type_defaults_to_no_transfer(): void
    {
        $this->model(['users' => 'members']);
        $l = (new TypeCatalog($this->dir))->get('member');

        $this->assertSame('members', $l->users);
        $this->assertFalse($l->transfer);
    }

    public function test_user_type_can_opt_in(): void
    {
        $this->model(['users' => 'members', 'transfer' => true]);

        $this->assertTrue((new TypeCatalog($this->dir))->get('member')->transfer);
    }

    public function test_non_user_type_can_opt_out(): void
    {
        $this->model(['transfer' => false]);
        $l = (new TypeCatalog($this->dir))->get('member');

        $this->assertNull($l->users);
        $this->assertFalse($l->transfer);
    }

    public function test_plain_type_transfers(): void
    {
        $this->model([]);

        $this->assertTrue((new TypeCatalog($this->dir))->get('member')->transfer);
    }

    public function test_user_type_with_frontend_throws(): void
    {
        $this->model(['users' => 'members', 'frontend' => ['route' => '/{slug}']]);
        $this->expectException(FrontendConfigException::class);

        (new TypeCatalog($this->dir))->all();
    }

    public function test_user_type_singleton_throws(): void
    {
        $this->model(['users' => 'members', 'singleton' => true]);
        $this->expectException(FrontendConfigException::class);

        (new TypeCatalog($this->dir))->all();
    }

    public function test_users_must_be_a_non_empty_string(): void
    {
        $this->model(['users' => '']);
        $this->expectException(FrontendConfigException::class);

        (new TypeCatalog($this->dir))->all();
    }

    public function test_non_bool_transfer_throws(): void
    {
        $this->model(['transfer' => 'false']);
        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Type "member": "transfer" must be true or false');

        (new TypeCatalog($this->dir))->all();
    }

    public function test_catalog_add_appears_after_files(): void
    {
        $this->model([]);
        $catalog = new TypeCatalog($this->dir);
        $catalog->add(['key' => 'early', 'table' => 'early', 'fields' => []]);
        $this->assertSame(['member', 'early'], array_map(fn ($l) => $l->key, $catalog->all()));

        // added after the first all() call: appended, in insertion order
        $catalog->add(['key' => 'late', 'table' => 'late', 'label' => 'Late', 'fields' => []], 'settings');
        $keys = array_map(fn ($l) => $l->key, $catalog->all());
        $this->assertSame(['member', 'early', 'late'], $keys);
        $this->assertSame('content', $catalog->get('early')->section);
        $this->assertSame('settings', $catalog->get('late')->section);
        $this->assertSame('Late', $catalog->get('late')->label);
    }

    public function test_catalog_add_duplicate_throws(): void
    {
        $this->model([]);
        $catalog = new TypeCatalog($this->dir);
        $catalog->add(['key' => 'ext', 'table' => 'ext', 'fields' => []]);

        $this->expectException(\RuntimeException::class);
        $catalog->add(['key' => 'ext', 'table' => 'ext', 'fields' => []]);
    }

    public function test_catalog_add_duplicate_of_file_throws(): void
    {
        $this->model([]);

        $this->expectException(\RuntimeException::class);
        (new TypeCatalog($this->dir))->add(['key' => 'member', 'table' => 'member', 'fields' => []]);
    }

    public function test_catalog_add_validates_like_scan(): void
    {
        $this->expectException(FrontendConfigException::class);
        (new TypeCatalog($this->dir))->add(['key' => 'u', 'table' => 'u', 'users' => '', 'fields' => []]);
    }

    public function test_nav_keys_default_and_read(): void
    {
        $catalog = new TypeCatalog($this->dir);
        $catalog->add(['key' => 'plain']);
        $catalog->add(['key' => 'placed', 'section' => 'pages', 'order' => 5, 'icon' => 'ui-users', 'nav' => false], 'settings');

        $plain = $catalog->get('plain');
        $this->assertSame(['content', 100, '', true], [$plain->section, $plain->order, $plain->icon, $plain->nav]);
        $placed = $catalog->get('placed');
        $this->assertSame(['pages', 5, 'ui-users', false], [$placed->section, $placed->order, $placed->icon, $placed->nav]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidNavKeys(): iterable
    {
        yield 'section not a string' => [['section' => 3], '"section"'];
        yield 'section not a slug' => [['section' => 'Member Area'], '"section"'];
        yield 'order not an int' => [['order' => '10'], '"order"'];
        yield 'icon without ui- prefix' => [['icon' => 'users'], '"icon"'];
        yield 'icon not a string' => [['icon' => true], '"icon"'];
        yield 'nav not a bool' => [['nav' => 'no'], '"nav"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNavKeys')]
    public function test_invalid_section_type_throws(array $extra, string $names): void
    {
        $this->model($extra);
        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessageMatches('/Type "member".*' . preg_quote($names, '/') . '/');

        (new TypeCatalog($this->dir))->all();
    }

    public function test_reserved_type_key_throws(): void
    {
        foreach (\Preflow\Folio\Extension\Screen\ScreenRegistry::RESERVED as $key) {
            try {
                (new TypeCatalog($this->dir))->add(['key' => $key]);
                $this->fail("Type key \"{$key}\" was accepted");
            } catch (FrontendConfigException $e) {
                $this->assertStringContainsString("Type \"{$key}\"", $e->getMessage());
            }
        }
    }

    public function test_non_transferred_type_with_asset_field_throws(): void
    {
        $catalog = new TypeCatalog($this->dir);
        $catalog->add(['key' => 'gallery', 'transfer' => true, 'fields' => ['image' => ['type' => 'asset']]]);
        $catalog->add(['key' => 'note', 'transfer' => false, 'fields' => ['body' => ['type' => 'text']]]);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Type "member" keeps its records on the server, but its files would still be exported; file fields are not supported on it yet.');

        $catalog->add(['key' => 'member', 'users' => 'members', 'fields' => ['name' => ['type' => 'string'], 'avatar' => ['type' => 'asset']]]);
    }

    public function test_upload_field_type_names_match_registered_types(): void
    {
        $uploads = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Field/Types/*FieldType.php') ?: [] as $file) {
            $class = 'Preflow\\Folio\\Field\\Types\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isAbstract() && $reflection->implementsInterface(\Preflow\Folio\Field\HandlesUpload::class)) {
                $uploads[] = $class;
            }
        }

        $this->assertSame([\Preflow\Folio\Field\Types\AssetFieldType::class], $uploads);
        $this->assertSame(['asset'], TypeCatalog::UPLOAD_FIELD_TYPES);
    }
}
