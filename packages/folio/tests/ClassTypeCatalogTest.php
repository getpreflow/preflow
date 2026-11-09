<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Tests\Fixtures\Models\Job;
use Preflow\Folio\Tests\Fixtures\Models\Poster;

/** A model class marked #[Entity(type: …)] is a type in Folio once an admin file for its key exists. */
final class ClassTypeCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_class_types_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_an_admin_file_lists_the_class_backed_type(): void
    {
        $this->admin('job', ['label' => 'Jobs', 'publishing' => true, 'list' => ['columns' => ['title', 'company']]]);

        $job = $this->catalog()->get('job');

        $this->assertNotNull($job);
        $this->assertSame('Jobs', $job->label);
        $this->assertTrue($job->publishing);
        $this->assertSame(['columns' => ['title', 'company']], $job->list);
    }

    public function test_a_list_column_naming_a_hidden_field_fails_as_for_json_types(): void
    {
        $this->admin('job', ['label' => 'Jobs', 'list' => ['columns' => ['title', 'notes']], 'fields' => ['notes' => ['hidden' => true]]]);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Type "job": list column "notes" names a hidden field');

        $this->catalog()->all();
    }

    public function test_the_file_check_sees_the_class_fields(): void
    {
        $this->admin('poster', ['label' => 'Posters', 'transfer' => false]);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Type "poster" keeps its records on the server');

        $this->catalog()->all();
    }

    public function test_a_class_without_an_admin_file_is_not_listed(): void
    {
        $registry = $this->registry();

        $this->assertFalse($this->catalog($registry)->has('job'));
        $this->assertTrue($registry->has('job'), 'still a type for app code');
    }

    public function test_json_types_are_listed_beside_class_types(): void
    {
        $this->admin('job', ['label' => 'Jobs']);
        file_put_contents($this->dir . '/page.json', json_encode(['key' => 'page', 'table' => 'page', 'label' => 'Pages', 'fields' => ['title' => ['type' => 'string']]]));

        $keys = array_map(static fn ($listing): string => $listing->key, $this->catalog()->all());
        sort($keys);

        $this->assertSame(['job', 'page'], $keys);
    }

    public function test_the_boot_manifest_keeps_the_listing_with_the_class_files_as_sources(): void
    {
        $this->admin('job', ['label' => 'Jobs']);
        $recording = \Preflow\Core\Boot\BootManifest::recording();

        (new TypeCatalog($this->dir, $recording, $this->registry()))->all();
        $recorded = $recording->recorded();

        $this->assertSame('Jobs', $recorded['catalogs']['folio.type_listings'][0]['label'] ?? null);
        $this->assertContains((string) (new \ReflectionClass(Job::class))->getFileName(), $recorded['sources'], 'a changed class makes the listing stale');
        $this->assertContains($this->dir, $recorded['sources']);

        $loaded = new TypeCatalog($this->dir, \Preflow\Core\Boot\BootManifest::fromData(['catalogs' => $recorded['catalogs']]), $this->registry());
        $this->assertSame('Jobs', $loaded->get('job')?->label, 'read back from the manifest');
    }

    private function registry(): TypeRegistry
    {
        return new TypeRegistry($this->dir, null, ['job' => Job::class, 'poster' => Poster::class]);
    }

    private function catalog(?TypeRegistry $registry = null): TypeCatalog
    {
        return new TypeCatalog($this->dir, null, $registry ?? $this->registry());
    }

    /** @param array<string, mixed> $settings */
    private function admin(string $key, array $settings): void
    {
        file_put_contents($this->dir . "/{$key}.json", (string) json_encode(['key' => $key] + $settings));
    }
}
