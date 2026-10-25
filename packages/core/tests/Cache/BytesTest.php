<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Cache\Bytes;

final class BytesTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function sizes(): array
    {
        return [
            'zero' => [0, '0 B'],
            'bytes' => [512, '512 B'],
            'kilobytes' => [1536, '1.5 KB'],
            'megabytes' => [(int) (3.1 * 1024 * 1024), '3.1 MB'],
            'gigabytes' => [2 * 1024 ** 3, '2.0 GB'],
        ];
    }

    #[DataProvider('sizes')]
    public function test_human(int $bytes, string $expected): void
    {
        $this->assertSame($expected, Bytes::human($bytes));
    }
}
