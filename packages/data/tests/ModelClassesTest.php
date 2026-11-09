<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Boot\BootManifest;
use Preflow\Data\ModelClasses;
use Preflow\Data\Tests\Fixtures\ModelSchema\Job;

final class ModelClassesTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        foreach (glob(__DIR__ . '/Fixtures/ModelSchema/*.php') as $fixture) {
            require_once $fixture;
        }
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_model_classes_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php'));
        rmdir($this->dir);
    }

    private function fixture(string ...$names): void
    {
        foreach ($names as $name) {
            copy(__DIR__ . '/Fixtures/ModelSchema/' . $name . '.php', $this->dir . '/' . $name . '.php');
        }
    }

    public function test_finds_a_type_marked_class_and_ignores_a_plain_model(): void
    {
        $this->fixture('Job', 'PlainPost');
        $classes = new ModelClasses([$this->dir]);

        $this->assertSame(['job' => Job::class], $classes->all());
        $this->assertSame(Job::class, $classes->classFor('job'));
        $this->assertNull($classes->classFor('plain'));
    }

    public function test_a_missing_path_holds_no_classes(): void
    {
        $this->assertSame([], (new ModelClasses([$this->dir . '/nope']))->all());
    }

    public function test_two_classes_with_one_key_are_an_error(): void
    {
        $this->fixture('Job');
        $twin = str_replace(['final class Job', 'namespace Preflow\Data\Tests\Fixtures\ModelSchema;'], ['final class JobTwin', 'namespace Preflow\Data\Tests\Twin;'], file_get_contents(__DIR__ . '/Fixtures/ModelSchema/Job.php'));
        file_put_contents($this->dir . '/JobTwin.php', $twin);
        eval('?>' . $twin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Job::class);
        $this->expectExceptionMessage('Preflow\Data\Tests\Twin\JobTwin');
        (new ModelClasses([$this->dir]))->all();
    }

    public function test_the_manifest_remembers_the_scan(): void
    {
        $this->fixture('Job');
        $recording = BootManifest::recording();
        $this->assertSame(['job' => Job::class], (new ModelClasses([$this->dir], $recording))->all());
        $catalogs = $recording->recorded()['catalogs'];
        $this->assertSame(['job' => Job::class], $catalogs['data.model_classes']);
        $this->assertContains($this->dir, $recording->recorded()['sources']);

        unlink($this->dir . '/Job.php'); // a loaded manifest does not scan
        $loaded = BootManifest::fromData(['catalogs' => $catalogs]);
        $this->assertSame(['job' => Job::class], (new ModelClasses([$this->dir], $loaded))->all());
    }
}
