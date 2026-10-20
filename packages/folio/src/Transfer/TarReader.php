<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/**
 * Reads a ustar archive from a stream. Only regular files (and directory
 * entries, which are skipped) are accepted; every name is checked, and a
 * truncated archive or a bad checksum throws.
 */
final class TarReader
{
    /** @param resource $stream */
    public function __construct(private $stream) {}

    /**
     * Each entry's copy function must be called before the next entry is read
     * (or the body is skipped).
     *
     * @return \Generator<int, array{string, int, \Closure(mixed): void}>
     */
    public function entries(): \Generator
    {
        while (true) {
            $h = $this->read(512);
            if ($h === str_repeat("\0", 512)) {
                if ($this->read(512) !== str_repeat("\0", 512)) {
                    throw new TransferException('Archive ends without its closing blocks');
                }
                return;
            }

            $stored = octdec(trim(substr($h, 148, 8), " \0"));
            $sum = array_sum(array_map('ord', str_split(substr_replace($h, '        ', 148, 8))));
            if ($stored !== $sum) {
                throw new TransferException('Archive entry has a bad checksum');
            }

            $name = rtrim(substr($h, 0, 100), "\0");
            $prefix = rtrim(substr($h, 345, 155), "\0");
            if ($prefix !== '' && substr($h, 257, 5) === 'ustar') {
                $name = $prefix . '/' . $name;
            }
            $type = $h[156];
            $size = (int) octdec(trim(substr($h, 124, 12), " \0"));
            $padded = $size + (512 - $size % 512) % 512;

            if ($type === '5') {
                TarName::assertSafe(rtrim($name, '/'));
                $this->skip($padded);
                continue;
            }
            if ($type !== '0' && $type !== "\0") {
                throw new TransferException("Archive entry {$name} is not a regular file");
            }
            TarName::assertSafe($name);

            $consumed = false;
            $copy = function ($target) use ($size, &$consumed): void {
                $out = is_string($target) ? fopen($target, 'wb') : $target;
                if ($out === false) {
                    throw new TransferException("Cannot write {$target}");
                }
                $left = $size;
                while ($left > 0) {
                    $chunk = $this->read(min(65536, $left));
                    fwrite($out, $chunk);
                    $left -= strlen($chunk);
                }
                if (is_string($target)) {
                    fclose($out);
                }
                $consumed = true;
            };

            yield [$name, $size, $copy];

            if (!$consumed) {
                $this->skip($size);
            }
            $this->skip($padded - $size);
        }
    }

    private function skip(int $bytes): void
    {
        while ($bytes > 0) {
            $bytes -= strlen($this->read(min(65536, $bytes)));
        }
    }

    private function read(int $bytes): string
    {
        $out = '';
        while (strlen($out) < $bytes) {
            $chunk = fread($this->stream, $bytes - strlen($out));
            if ($chunk === false || $chunk === '') {
                throw new TransferException('Archive is truncated');
            }
            $out .= $chunk;
        }

        return $out;
    }
}
