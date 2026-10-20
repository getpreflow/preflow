<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/** Entry names that can only ever land inside the extraction directory. */
final class TarName
{
    public static function assertSafe(string $name): void
    {
        $bad = $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || preg_match('#(^|/)\.{1,2}(/|$)#', $name) === 1
            || preg_match('#//#', $name) === 1;
        if ($bad) {
            throw new TransferException("Unsafe archive entry name: {$name}");
        }
    }
}
