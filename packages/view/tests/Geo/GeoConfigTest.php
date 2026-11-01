<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Geo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\GeoConfig;

final class GeoConfigTest extends TestCase
{
    public function test_missing_file_is_off_with_defaults(): void
    {
        $config = GeoConfig::fromFile(sys_get_temp_dir() . '/no-such-geo-' . bin2hex(random_bytes(4)) . '.php');

        $this->assertFalse($config->enabled());
        $this->assertNull($config->name);
        $this->assertNull($config->summary);
        $this->assertSame(['name' => 'name', 'summary' => 'meta_description'], $config->siteFields);
        $this->assertSame(['page'], $config->types);
        $this->assertNull($config->noindexField);
        $this->assertTrue($config->full);
        $this->assertSame([], $config->exclude);
    }

    public function test_reads_every_key(): void
    {
        $path = sys_get_temp_dir() . '/geo-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, "<?php\nreturn " . var_export([
            'enabled' => true,
            'name' => 'Site',
            'summary' => 'About the site',
            'site_fields' => ['name' => 'title', 'summary' => 'lead'],
            'types' => ['page', 'post'],
            'noindex_field' => 'hidden',
            'full' => false,
            'exclude' => ['/thanks'],
        ], true) . ";\n");

        try {
            $config = GeoConfig::fromFile($path);
        } finally {
            unlink($path);
        }

        $this->assertTrue($config->enabled());
        $this->assertSame('Site', $config->name);
        $this->assertSame('About the site', $config->summary);
        $this->assertSame(['name' => 'title', 'summary' => 'lead'], $config->siteFields);
        $this->assertSame(['page', 'post'], $config->types);
        $this->assertSame('hidden', $config->noindexField);
        $this->assertFalse($config->full);
        $this->assertSame(['/thanks'], $config->exclude);
    }

    public function test_excludes_ignores_a_trailing_slash(): void
    {
        $config = GeoConfig::fromArray(['exclude' => ['/thanks/', '/']]);

        $this->assertTrue($config->excludes('/thanks'));
        $this->assertTrue($config->excludes('/thanks/'));
        $this->assertTrue($config->excludes('/'));
        $this->assertFalse($config->excludes('/thanks/more'));
    }
}
