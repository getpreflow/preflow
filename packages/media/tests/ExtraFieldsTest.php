<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Extra asset fields from config/media.php: their own columns, stored by update(), kept by replace and restore. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class ExtraFieldsTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @return array<string, mixed> */
    public static function fields(): array
    {
        return [
            'usage_type' => ['type' => 'select', 'label' => 'Usage', 'options' => ['cover' => 'Cover', 'step' => 'Step']],
            'game' => ['type' => 'relation', 'relation' => ['to' => 'game', 'multiple' => true]],
            'featured' => ['type' => 'boolean'],
        ];
    }

    private function lib(): MediaLibrary
    {
        return $this->library(
            ['fields' => self::fields(), 'filters' => ['usage_type', 'game'], 'versions_dir' => $this->assetRoot . '/versions'],
            new VariantGenerator(new GdBackend(), [480], 75),
        );
    }

    public function test_the_fields_are_columns_of_the_asset_type(): void
    {
        $fields = $this->lib()->definition()->fields;

        $this->assertSame('select', $fields['usage_type']->type);
        $this->assertSame('Usage', $fields['usage_type']->label);
        $this->assertSame(['cover' => 'Cover', 'step' => 'Step'], $fields['usage_type']->config['options']);
        $this->assertSame('game', $fields['game']->config['relation']['to']);
        $this->assertArrayNotHasKey('extra', $fields);
    }

    public function test_update_stores_the_values(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(), 'a.png'), null);
        $this->assertSame(['usage_type' => '', 'game' => [], 'featured' => false], $asset->extra);

        $lib->update($asset->id, ['usage_type' => 'cover', 'game' => ['g1', 'g2'], 'featured' => '1']);

        $extra = $lib->find($asset->id)->extra;
        $this->assertSame('cover', $extra['usage_type']);
        $this->assertSame(['g1', 'g2'], $extra['game']);
        $this->assertTrue($extra['featured']);
    }

    public function test_update_clears_a_select_value_that_is_not_an_option_and_ignores_unknown_keys(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(), 'a.png'), null);
        $lib->update($asset->id, ['usage_type' => 'cover']);

        $lib->update($asset->id, ['usage_type' => 'bogus', 'nonsense' => 'x', 'path' => 'evil.png']);

        $saved = $lib->find($asset->id);
        $this->assertSame('', $saved->extra['usage_type']);
        $this->assertArrayNotHasKey('nonsense', $saved->extra);
        $this->assertSame($asset->path, $saved->path);
    }

    public function test_other_updates_leave_the_values_alone(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(), 'a.png'), null);
        $lib->update($asset->id, ['usage_type' => 'step', 'game' => ['g1']]);

        $lib->update($asset->id, ['title' => 'Hello']);

        $this->assertSame('step', $lib->find($asset->id)->extra['usage_type']);
        $this->assertSame(['g1'], $lib->find($asset->id)->extra['game']);
    }

    public function test_the_values_survive_replace_and_restore(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);
        $lib->update($asset->id, ['usage_type' => 'cover', 'game' => ['g1'], 'featured' => true]);

        $replaced = $lib->replace($asset->id, $this->upload($this->png(900, 600), 'b.png'), null);
        $this->assertSame('cover', $replaced->extra['usage_type']);
        $this->assertSame(['g1'], $replaced->extra['game']);

        $lib->update($asset->id, ['usage_type' => 'step']);
        $restored = $lib->restore($asset->id, 1, null);
        $this->assertSame('step', $restored->extra['usage_type']);
        $this->assertSame(['g1'], $restored->extra['game']);
        $this->assertTrue($restored->extra['featured']);
    }

    public function test_no_fields_means_no_extra_values(): void
    {
        $lib = $this->library();
        $asset = $lib->upload($this->upload($this->png(), 'a.png'), null);

        $this->assertSame([], $asset->extra);
    }

    // --- config ---

    private function configDir(string $php): Application
    {
        $dir = sys_get_temp_dir() . '/media_fields_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/media.php', '<?php return ' . $php . ';');
        $this->assetRoot = $dir; // removed in tearDown

        return Application::create($dir);
    }

    public function test_config_reads_fields_and_filters(): void
    {
        $debug = new \Preflow\Core\Debug\DebugCollector();
        $config = MediaConfig::fromApp($this->configDir("['fields' => " . var_export(self::fields(), true) . ", 'filters' => ['usage_type', 'game', 'featured', 'nope', 'path']]"), $debug);

        $this->assertSame(self::fields(), $config->fields);
        $this->assertSame(['usage_type', 'game', 'featured'], $config->filters); // only declared select/relation/boolean fields
        $this->assertSame($config->fields, $config->toArray()['fields']);
        $this->assertSame($config->filters, $config->toArray()['filters']);
        $this->assertCount(2, $debug->getDeprecations()); // "nope" and "path" are reported, not dropped silently
        $this->assertStringContainsString('"nope"', $debug->getDeprecations()[0]);
    }

    public function test_a_filter_on_a_text_field_is_dropped(): void
    {
        $debug = new \Preflow\Core\Debug\DebugCollector();
        $config = MediaConfig::fromApp($this->configDir("['fields' => ['note2' => ['type' => 'string']], 'filters' => ['note2']]"), $debug);

        $this->assertSame([], $config->filters);
        $this->assertCount(1, $debug->getDeprecations());
    }

    public function test_a_field_named_like_an_asset_key_is_a_boot_error(): void
    {
        foreach (['path', 'alt', 'preview', 'text_color', 'private', 'versions', 'uuid', 'Path'] as $name) {
            try {
                MediaConfig::fromApp($this->configDir("['fields' => ['{$name}' => ['type' => 'string']]]"));
                $this->fail("{$name} was accepted");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
                $this->assertStringContainsString('config/media.php', $e->getMessage());
            }
        }
    }

    public function test_a_field_name_that_is_not_a_column_name_is_a_boot_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bad name');

        MediaConfig::fromApp($this->configDir("['fields' => ['bad name' => ['type' => 'string']]]"));
    }

    // --- table step ---

    public function test_the_table_step_adds_the_columns_to_an_existing_table_without_data_loss(): void
    {
        $dir = sys_get_temp_dir() . '/media_fields_tbl_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        $this->assetRoot = $dir;
        file_put_contents($dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($dir . '/app.sqlite', true) . ']]];');
        file_put_contents($dir . '/config/providers.php', '<?php return [' . MediaServiceProvider::class . '::class];');
        file_put_contents($dir . '/config/media.php', '<?php return ["storage" => "sqlite"];');
        $run = function () use ($dir): string {
            $app = Application::create($dir);
            $app->boot();
            ob_start();
            (new MigrateCommand(fn () => $app))->execute([]);

            return (string) ob_get_clean();
        };
        $run();
        $pdo = new \PDO('sqlite:' . $dir . '/app.sqlite');
        $pdo->exec("INSERT INTO folio_assets (uuid, path, title) VALUES ('" . str_repeat('a', 32) . "', '2026/10/x.png', 'Kept')");
        $this->assertNotContains('usage_type', $pdo->query('SELECT name FROM pragma_table_info("folio_assets")')->fetchAll(\PDO::FETCH_COLUMN));

        file_put_contents($dir . '/config/media.php', '<?php return ["storage" => "sqlite", "fields" => ' . var_export(self::fields(), true) . '];');
        $out = $run();

        $this->assertStringContainsString('added column folio_assets.usage_type', $out);
        $columns = $pdo->query('SELECT name FROM pragma_table_info("folio_assets")')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['usage_type', 'game', 'featured'] as $column) {
            $this->assertContains($column, $columns);
        }
        $this->assertSame('Kept', $pdo->query('SELECT title FROM folio_assets')->fetchColumn());
    }
}
