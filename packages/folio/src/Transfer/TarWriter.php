<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/**
 * Writes a ustar archive to a stream, one entry at a time (no temp files, so
 * an export can go straight to stdout). Regular files only.
 */
final class TarWriter
{
    /** @param resource $stream */
    public function __construct(private $stream) {}

    public function addString(string $name, string $bytes): void
    {
        $this->header($name, strlen($bytes));
        $this->write($bytes);
        $this->pad(strlen($bytes));
    }

    public function addFile(string $name, string $sourcePath): void
    {
        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new TransferException("Cannot read {$sourcePath}");
        }
        try {
            $size = (int) fstat($in)['size'];
            $this->header($name, $size);
            $copied = stream_copy_to_stream($in, $this->stream);
            if ($copied !== $size) {
                throw new TransferException("{$sourcePath} changed while it was archived");
            }
            $this->pad($size);
        } finally {
            fclose($in);
        }
    }

    /** The two zero blocks that end an archive. */
    public function close(): void
    {
        $this->write(str_repeat("\0", 1024));
        fflush($this->stream);
    }

    private function header(string $name, int $size): void
    {
        TarName::assertSafe($name);
        [$prefix, $short] = self::split($name);
        $h = str_pad($short, 100, "\0")
            . str_pad('0000644', 8, "\0")
            . str_pad('0000000', 8, "\0")
            . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', $size), 12, "\0")
            . str_pad(sprintf('%011o', time()), 12, "\0")
            . '        '            // checksum, filled below
            . '0'                   // regular file
            . str_repeat("\0", 100) // link name
            . "ustar\0" . '00'
            . str_repeat("\0", 32 + 32 + 8 + 8)
            . str_pad($prefix, 155, "\0")
            . str_repeat("\0", 12);
        $sum = array_sum(array_map('ord', str_split($h)));
        $this->write(substr_replace($h, sprintf("%06o\0 ", $sum), 148, 8));
    }

    /** @return array{string, string} ustar prefix (<= 155) and name (<= 100) */
    private static function split(string $name): array
    {
        if (strlen($name) <= 100) {
            return ['', $name];
        }
        $cut = strrpos(substr($name, 0, 156), '/');
        if ($cut === false || strlen($name) - $cut - 1 > 100 || $cut === 0) {
            throw new TransferException("Archive entry name too long: {$name}");
        }

        return [substr($name, 0, $cut), substr($name, $cut + 1)];
    }

    private function pad(int $size): void
    {
        $this->write(str_repeat("\0", (512 - $size % 512) % 512));
    }

    private function write(string $bytes): void
    {
        if ($bytes !== '' && fwrite($this->stream, $bytes) !== strlen($bytes)) {
            throw new TransferException('Could not write the archive');
        }
    }
}
