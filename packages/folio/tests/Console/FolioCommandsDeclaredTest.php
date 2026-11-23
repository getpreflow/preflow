<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Console\FolioCommands;

final class FolioCommandsDeclaredTest extends TestCase
{
    public function test_composer_extra_lists_every_folio_command(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $declared = $composer['extra']['preflow']['commands'] ?? [];

        $expected = FolioCommands::all();
        sort($declared);
        sort($expected);

        $this->assertSame($expected, $declared);
    }
}
