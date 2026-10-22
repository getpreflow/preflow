<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionsFile;

final class ExtensionsFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_extfile_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/folio/extensions.json');
        @rmdir($this->dir . '/config/folio');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_file_roundtrip_is_stable(): void
    {
        $file = new ExtensionsFile($this->dir . '/config/folio/extensions.json');
        $file->write([
            'shop' => ['disabled_parts' => ['type:post', 'screen:orders']],
            'members' => ['disabled_parts' => []],
        ]);
        $bytes = (string) file_get_contents($file->path());

        $this->assertSame(<<<'JSON'
            {
                "enabled": {
                    "members": {
                        "disabled_parts": []
                    },
                    "shop": {
                        "disabled_parts": [
                            "screen:orders",
                            "type:post"
                        ]
                    }
                }
            }

            JSON, $bytes);

        $file->write($file->read());
        $this->assertSame($bytes, (string) file_get_contents($file->path()));
    }

    public function test_empty_file_writes_an_object(): void
    {
        $file = new ExtensionsFile($this->dir . '/config/folio/extensions.json');
        $file->write([]);

        $this->assertSame("{\n    \"enabled\": {}\n}\n", (string) file_get_contents($file->path()));
        $this->assertSame([], $file->read());
    }

    public function test_missing_file_reads_empty(): void
    {
        $file = new ExtensionsFile($this->dir . '/config/folio/extensions.json');

        $this->assertFalse($file->exists());
        $this->assertSame([], $file->read());
    }

    public function test_entry_without_disabled_parts_reads_as_none(): void
    {
        $this->put('{"enabled": {"members": {}}}');

        $this->assertSame(['members' => ['disabled_parts' => []]], (new ExtensionsFile($this->dir . '/config/folio/extensions.json'))->read());
    }

    public function test_malformed_file_throws(): void
    {
        $this->put('{"enabled": ["members"');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('extensions.json');
        (new ExtensionsFile($this->dir . '/config/folio/extensions.json'))->read();
    }

    private function put(string $json): void
    {
        @mkdir($this->dir . '/config/folio', 0777, true);
        file_put_contents($this->dir . '/config/folio/extensions.json', $json);
    }
}
