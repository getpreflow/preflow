<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;

/**
 * config/i18n.php is read in core (Application, LocaleUrls), Folio and this package. The API
 * reference lists the keys from packages/i18n/config/i18n.php, so every key the code reads must be
 * declared there (since 0.84.1).
 */
final class ConfigDeclaredTest extends TestCase
{
    /**
     * Every file that reads configFile('i18n'). A new reader must be added here, or its keys go
     * unchecked and may be left undeclared.
     */
    private const array READERS = [
        'core/src/Application.php',
        'core/src/Http/LocaleUrls.php',
        'folio/src/FolioServiceProvider.php',
        'i18n/src/LocaleMiddleware.php',
        'i18n/src/TranslationExtensionProvider.php',
    ];

    public function testEveryKeyTheCodeReadsIsDeclared(): void
    {
        $this->assertFileExists(self::declaredFile());
        $declared = array_keys((array) require self::declaredFile());
        $read = $this->keysRead();

        $this->assertContains('cookie_name', $read, 'the scan finds nothing: its patterns are out of date');
        $this->assertSame([], array_values(array_diff($read, $declared)), 'keys read from config/i18n.php but not declared in packages/i18n/config/i18n.php');
    }

    public function testEveryDeclaredKeyIsRead(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys((array) require self::declaredFile()), $this->keysRead())));
    }

    private static function declaredFile(): string
    {
        return dirname(__DIR__) . '/config/i18n.php';
    }

    /** @return list<string> */
    private function keysRead(): array
    {
        $keys = [];
        foreach (self::READERS as $file) {
            $path = dirname(__DIR__, 2) . '/' . $file;
            $this->assertFileExists($path, "READERS names {$file}, which is gone: rename it here too.");
            $source = (string) file_get_contents($path);
            preg_match_all('/\$i18n(?:Config)?\[\s*\'(\w+)\'\s*\]/', $source, $m);
            array_push($keys, ...$m[1]);
            // A list of keys read in a loop, as LocaleUrls does for its flags.
            if (preg_match_all('/foreach \(\[((?:\'\w+\',?\s*)+)\] as \$key\)/', $source, $lists) > 0) {
                foreach ($lists[1] as $list) {
                    preg_match_all('/\'(\w+)\'/', $list, $names);
                    array_push($keys, ...$names[1]);
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
