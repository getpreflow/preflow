<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Tests\Support\TempLang;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;

/** Packages declare their language folders in composer.json (extra.preflow.translations). */
final class TranslationRegistryTest extends TestCase
{
    use TempLang;

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $this->errorLog = ini_get('error_log');
        ini_set('error_log', $this->tmp() . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        $this->removeTmp();
    }

    /** @param list<array<string, mixed>> $packages */
    private function installed(array $packages): string
    {
        return $this->file('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    }

    private function fixture(): string
    {
        $this->langFile('vendor/acme/media/lang', 'en', 'media', ['k' => 'Media text']);
        $this->langFile('vendor/acme/members/lang', 'en', 'members', ['k' => 'Members text']);
        $this->langFile('vendor/acme/x/lang', 'en', 'x', ['k' => 'X text']);

        return $this->installed([
            ['name' => 'acme/media', 'install-path' => '../acme/media', 'extra' => ['preflow' => ['translations' => ['media' => ['path' => 'lang', 'scope' => 'admin']]]]],
            ['name' => 'acme/members', 'install-path' => '../acme/members', 'extra' => ['preflow' => ['translations' => ['members' => ['path' => 'lang', 'scope' => 'public']]]]],
            ['name' => 'acme/x', 'install-path' => '../acme/x', 'extra' => ['preflow' => ['translations' => ['x' => 'lang']]]],
            ['name' => 'acme/plain', 'install-path' => '../acme/plain'],
        ]);
    }

    public function test_reads_groups_and_scopes(): void
    {
        $registry = TranslationRegistry::fromInstalled($this->fixture());

        $groups = $registry->groups();
        $this->assertSame(['media', 'members', 'x'], array_keys($groups));
        $this->assertSame('admin', $groups['media']['scope']);
        $this->assertSame('public', $groups['members']['scope']);
        $this->assertSame('admin', $groups['x']['scope'], 'a plain string path means admin');
        $this->assertSame(realpath($this->tmp() . '/vendor/acme/media/lang'), realpath($groups['media']['path']));
        $this->assertSame('public', $registry->scope('members'));
        $this->assertSame('admin', $registry->scope('x'));
        $this->assertNull($registry->scope('nope'));
    }

    public function test_apply_registers_the_groups_with_a_translator(): void
    {
        $registry = TranslationRegistry::fromInstalled($this->fixture());
        $t = new Translator($this->tmp() . '/lang', 'en', 'en');

        $registry->apply($t);

        $this->assertSame('Media text', $t->get('media.k'));
        $this->assertSame('Members text', $t->get('members.k'));
        $this->assertSame('X text', $t->get('x.k'));
        $this->assertSame('public', $t->scope('members'));
        $this->assertSame('admin', $t->scope('media'));
    }

    public function test_a_missing_installed_json_gives_an_empty_registry(): void
    {
        $this->assertSame([], TranslationRegistry::fromInstalled($this->tmp() . '/vendor/composer/installed.json')->groups());
    }

    public function test_invalid_declarations_are_skipped_and_logged(): void
    {
        $this->langFile('vendor/acme/a/lang', 'en', 'a', ['k' => 'A']);
        $file = $this->installed([
            ['name' => 'acme/a', 'install-path' => '../acme/a', 'extra' => ['preflow' => ['translations' => [
                'a' => ['path' => 'lang', 'scope' => 'admin'],
                'bad' => ['path' => 'lang', 'scope' => 'secret'],
                'gone' => ['path' => 'nowhere', 'scope' => 'admin'],
                'weird' => 42,
            ]]]],
            ['name' => 'acme/b', 'install-path' => '../acme/b', 'extra' => ['preflow' => ['translations' => 'lang']]],
        ]);

        $registry = TranslationRegistry::fromInstalled($file);

        $this->assertSame(['a'], array_keys($registry->groups()));
        $log = (string) file_get_contents($this->tmp() . '/error.log');
        $this->assertStringContainsString('bad', $log);
        $this->assertStringContainsString('secret', $log);
        $this->assertStringContainsString('gone', $log);
    }

    public function test_broken_json_never_breaks_boot(): void
    {
        $file = $this->file('vendor/composer/installed.json', '{not json');

        $this->assertSame([], TranslationRegistry::fromInstalled($file)->groups());
    }

    public function test_the_package_root_is_known_per_group(): void
    {
        $registry = TranslationRegistry::fromInstalled($this->fixture());

        $this->assertSame(realpath($this->tmp() . '/vendor/acme/media'), realpath((string) $registry->packagePath('media')));
        $this->assertNull($registry->packagePath('nope'));
    }
}
