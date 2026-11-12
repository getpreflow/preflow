<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;

/** The package declares its language files (extra.preflow.translations), found through Composer's metadata. */
final class TranslationsDeclarationTest extends TestCase
{
    public function test_the_declaration_registers_the_group(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $installed = sys_get_temp_dir() . '/preflow_installed_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installed, (string) json_encode(['packages' => [[...$composer, 'install-path' => $root]]]));

        try {
            $registry = TranslationRegistry::fromInstalled($installed);
        } finally {
            unlink($installed);
        }

        $this->assertSame('public', $registry->scope('members'));
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $registry->apply($translator);
        $this->assertNotSame([], $translator->entries('members', 'en'));
        foreach (['de', 'fr'] as $locale) {
            $this->assertEqualsCanonicalizing(array_keys($translator->entries('members', 'en')), array_keys($translator->entries('members', $locale)), $locale);
        }
    }

    public function test_the_admin_texts_are_not_the_core_s(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertArrayNotHasKey('members-admin', $composer['extra']['preflow']['translations']);
        $this->assertDirectoryDoesNotExist(dirname(__DIR__) . '/lang/en/members-admin.php');
        $this->assertFalse(method_exists(\Preflow\Members\Links::class, 'folioMember'));
    }
}
