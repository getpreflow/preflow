<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockConfigException;
use Preflow\Blocks\BlockRegistry;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Fields\FieldOptions;
use Preflow\Fields\Tests\Support\BadZoneBlock;

/**
 * The options of the fields' types are boot errors without Folio too (0.91): FieldsServiceProvider
 * checks every type's fields at boot (kept in the boot manifest) and every block's fields when the
 * block registry is built. A show_when may test a structured, datetime or time field only with
 * "empty" or "filled".
 */
final class BootChecksTest extends TestCase
{
    private string $dir;

    private string $previousZone = 'UTC';

    protected function setUp(): void
    {
        $this->previousZone = date_default_timezone_get();
        $this->dir = sys_get_temp_dir() . '/fields_boot_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Fields', 'debug' => 1, 'engine' => 'twig', 'timezone' => 'Europe/Zurich', 'key' => 'fields-boot-test-key-0123456789abcdef'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousZone);
        Contexts::reset();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_a_misspelt_zone_stops_the_boot_without_folio_naming_type_and_field(): void
    {
        $this->providers([\Preflow\Fields\FieldsServiceProvider::class]);
        $this->model('event', ['title' => ['type' => 'string'], 'starts' => ['type' => 'datetime', 'zone' => 'Europe/Zurch']]);

        try {
            $this->boot();
            $this->fail('booted with a zone that does not exist');
        } catch (\LogicException $e) {
            $this->assertSame('Type "event": field "starts": "zone" is "Europe/Zurch"; use a time zone name such as "Europe/Zurich" or "UTC", or leave it out for the site\'s zone (app.timezone).', $e->getMessage());
        }
    }

    public function test_good_options_boot(): void
    {
        $this->providers([\Preflow\Fields\FieldsServiceProvider::class]);
        $this->model('event', [
            'starts' => ['type' => 'datetime', 'zone' => 'Europe/Zurich'],
            'note' => ['type' => 'string', 'show_when' => ['field' => 'starts', 'filled' => true]],
            'kind' => ['type' => 'string'],
            'brand' => ['type' => 'color', 'palette' => ['#c00000'], 'palette_only' => true, 'show_when' => ['field' => 'kind', '=' => 'promo']],
        ]);

        $this->assertTrue($this->boot()->container()->has(\Preflow\Data\DataManager::class));
    }

    public function test_a_show_when_comparing_a_link_with_a_value_stops_the_boot(): void
    {
        $this->providers([\Preflow\Fields\FieldsServiceProvider::class]);
        $this->model('teaser', [
            'cta' => ['type' => 'link'],
            'note' => ['type' => 'string', 'show_when' => ['any' => [['field' => 'cta', 'empty' => true], ['field' => 'cta', '!=' => 'x']]]],
        ]);

        try {
            $this->boot();
            $this->fail('booted with a link compared with "!="');
        } catch (\LogicException $e) {
            $this->assertSame('Type "teaser": field "note": show_when compares "cta" with "!=", but "cta" is a link field, which show_when can only test with "empty" or "filled" (the browser and the server cannot compare its value the same way). Write {"field": "cta", "filled": true} or {"field": "cta", "empty": true}.', $e->getMessage());
        }
    }

    public function test_a_block_field_option_stops_the_block_registry_build_without_folio(): void
    {
        $this->providers([\Preflow\Blocks\BlocksServiceProvider::class, \Preflow\Fields\FieldsServiceProvider::class]);
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\" . BadZoneBlock::class . "::class]];\n");

        $app = $this->boot();
        try {
            $app->container()->get(BlockRegistry::class);
            $this->fail('built a block registry with a zone that does not exist');
        } catch (BlockConfigException $e) {
            $this->assertSame('Block "bad_zone": field "starts": "zone" is "Europe/Zurch"; use a time zone name such as "Europe/Zurich" or "UTC", or leave it out for the site\'s zone (app.timezone).', $e->getMessage());
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string|null, 2: string}> */
    public static function showWhenSources(): iterable
    {
        foreach (FieldOptions::PRESENCE_ONLY as $type) {
            yield "{$type} filled" => [['field' => 'src', 'filled' => true], null, $type];
            yield "{$type} empty in all" => [['all' => [['field' => 'src', 'empty' => true]]], null, $type];
            yield "{$type} =" => [['field' => 'src', '=' => 'x'], '=', $type];
            yield "{$type} in" => [['field' => 'src', 'in' => ['x']], 'in', $type];
        }
        foreach (['string', 'email', 'color', 'code', 'json', 'select'] as $type) {
            yield "{$type} =" => [['field' => 'src', '=' => 'x'], null, $type];
        }
    }

    /** @param array<string, mixed> $showWhen */
    #[DataProvider('showWhenSources')]
    public function test_which_operators_a_show_when_source_takes(array $showWhen, ?string $refused, string $type): void
    {
        $fields = ['src' => new TypeFieldDefinition('src', $type), 'f' => new TypeFieldDefinition('f', 'string', config: ['show_when' => $showWhen])];
        if ($refused === null) {
            FieldOptions::checkFields($fields);
            $this->addToAssertionCount(1);

            return;
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("field \"f\": show_when compares \"src\" with \"{$refused}\", but \"src\" is ");
        FieldOptions::checkFields($fields);
    }

    /** @param list<class-string> $providers */
    private function providers(array $providers): void
    {
        $this->write('config/providers.php', "<?php\nreturn [" . implode(', ', array_map(static fn (string $p): string => "\\{$p}::class", $providers)) . "];\n");
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function model(string $key, array $fields): void
    {
        $this->write("config/models/{$key}.json", (string) json_encode(['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid', 'fields' => $fields]));
    }

    private function boot(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
