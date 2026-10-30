<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Settings;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Settings\FileAiSettingsStore;

final class FileAiSettingsStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ai_settings_' . bin2hex(random_bytes(4)) . '/nested';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
    }

    public function test_put_get_roundtrip_and_missing_section_is_empty(): void
    {
        $store = new FileAiSettingsStore($this->dir);
        $this->assertSame([], $store->get('tuning'));

        $store->put('tuning', ['lines' => ['Use "du".', 'Umlaute: ä']]);
        $store->put('glossary', [['term' => 'Meeple']]);

        $fresh = new FileAiSettingsStore($this->dir);
        $this->assertSame(['lines' => ['Use "du".', 'Umlaute: ä']], $fresh->get('tuning'));
        $this->assertSame([['term' => 'Meeple']], $fresh->get('glossary'));
        $this->assertSame([], $fresh->get('other'));
    }

    public function test_atomic_write(): void
    {
        $store = new FileAiSettingsStore($this->dir);
        $store->put('tuning', ['a']);
        $inode = fileinode($this->dir . '/settings.json');

        $store->put('tuning', ['b']);

        // A rename swaps in a new file (new inode) and leaves no temp file behind.
        $this->assertNotSame($inode, fileinode($this->dir . '/settings.json'));
        $this->assertSame(['settings.json'], array_map('basename', glob($this->dir . '/*') ?: []));
        $this->assertSame(['b'], $store->get('tuning'));
    }

    public function test_damaged_file_reads_as_empty(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/settings.json', '{broken');

        $this->assertSame([], (new FileAiSettingsStore($this->dir))->get('tuning'));
    }
}
