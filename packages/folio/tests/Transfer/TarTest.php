<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Transfer\TarReader;
use Preflow\Folio\Transfer\TarWriter;
use Preflow\Folio\Transfer\TransferException;

final class TarTest extends TestCase
{
    /** @return resource */
    private function memory()
    {
        return fopen('php://temp', 'w+b');
    }

    /** @return array<string, string> name => bytes */
    private function readAll($stream): array
    {
        rewind($stream);
        $out = [];
        foreach ((new TarReader($stream))->entries() as [$name, $size, $copyTo]) {
            $buf = fopen('php://temp', 'w+b');
            $copyTo($buf);
            rewind($buf);
            $out[$name] = (string) stream_get_contents($buf);
            $this->assertSame($size, strlen($out[$name]));
        }

        return $out;
    }

    /** A single raw ustar header + body, for crafting hostile entries. */
    private function rawEntry(string $name, string $body, string $type = '0', ?int $checksumDelta = 0): string
    {
        $h = str_pad($name, 100, "\0") . str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', strlen($body)), 12, "\0") . str_pad(sprintf('%011o', 0), 12, "\0")
            . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
        $sum = array_sum(array_map('ord', str_split($h))) + $checksumDelta;
        $h = substr_replace($h, sprintf("%06o\0 ", $sum), 148, 8);

        return $h . $body . str_repeat("\0", (512 - strlen($body) % 512) % 512);
    }

    public function test_round_trip_names_and_bytes(): void
    {
        $long = str_repeat('nested-directory/', 10) . 'file.json'; // > 100 bytes
        $binary = random_bytes(1300);
        $src = tempnam(sys_get_temp_dir(), 'tar');
        file_put_contents($src, $binary);

        $s = $this->memory();
        $w = new TarWriter($s);
        $w->addString('folio-export.json', '{"format":1}');
        $w->addString($long, 'long');
        $w->addFile('media/2026/10/a.bin', $src);
        $w->addString('empty.txt', '');
        $w->close();
        unlink($src);

        $this->assertSame(['folio-export.json' => '{"format":1}', $long => 'long', 'media/2026/10/a.bin' => $binary, 'empty.txt' => ''], $this->readAll($s));
    }

    /** @return array<string, array{string}> */
    public static function unsafeNames(): array
    {
        return ['parent' => ['../x'], 'absolute' => ['/etc/x'], 'inner parent' => ['a/../../x'], 'backslash' => ['a\\b'], 'dot segment' => ['a/./b']];
    }

    #[DataProvider('unsafeNames')]
    public function test_rejects_unsafe_names(string $name): void
    {
        $s = $this->memory();
        fwrite($s, $this->rawEntry($name, 'x') . str_repeat("\0", 1024));

        $this->expectException(TransferException::class);
        $this->readAll($s);
    }

    public function test_writer_refuses_unsafe_names(): void
    {
        $this->expectException(TransferException::class);
        (new TarWriter($this->memory()))->addString('../x', 'x');
    }

    public function test_rejects_symlink_entries(): void
    {
        $s = $this->memory();
        fwrite($s, $this->rawEntry('link', '', '2') . str_repeat("\0", 1024));

        $this->expectException(TransferException::class);
        $this->readAll($s);
    }

    public function test_rejects_truncated_stream(): void
    {
        $s = $this->memory();
        $w = new TarWriter($s);
        $w->addString('a.txt', str_repeat('x', 2000));
        rewind($s);
        $cut = $this->memory();
        fwrite($cut, (string) fread($s, 1024)); // header + part of the body, no end blocks

        $this->expectException(TransferException::class);
        $this->readAll($cut);
    }

    public function test_rejects_missing_end_blocks(): void
    {
        $s = $this->memory();
        fwrite($s, $this->rawEntry('a.txt', 'x')); // an archive that just stops

        $this->expectException(TransferException::class);
        $this->readAll($s);
    }

    public function test_rejects_bad_checksum(): void
    {
        $s = $this->memory();
        fwrite($s, $this->rawEntry('a.txt', 'x', '0', 1) . str_repeat("\0", 1024));

        $this->expectException(TransferException::class);
        $this->readAll($s);
    }

    public function test_short_write_while_extracting_throws(): void
    {
        $s = $this->memory();
        $w = new TarWriter($s);
        $w->addString('a.txt', str_repeat('x', 5000));
        $w->close();
        rewind($s);
        $readOnly = fopen('php://memory', 'rb'); // every write fails, like a full disk

        $this->expectException(TransferException::class);
        foreach ((new TarReader($s))->entries() as [, , $copyTo]) {
            $copyTo($readOnly);
        }
    }

    public function test_skips_directory_entries(): void
    {
        $s = $this->memory();
        fwrite($s, $this->rawEntry('media/', '', '5') . $this->rawEntry('media/a.txt', 'a') . str_repeat("\0", 1024));

        $this->assertSame(['media/a.txt' => 'a'], $this->readAll($s));
    }
}
