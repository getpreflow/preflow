<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;
use Preflow\Rbac\Messages;

/** The delegation messages in en, de and fr; a site's lang file changes single keys. */
final class LangTest extends TestCase
{
    private function messages(string $locale, string $siteLang = '/no/such/lang'): Messages
    {
        $translator = new Translator($siteLang, $locale, 'en');
        $translator->addDefaults('rbac', dirname(__DIR__) . '/lang');

        return new Messages($translator);
    }

    public function test_english_copy(): void
    {
        $en = $this->messages('en');
        $this->assertSame("You can't grant permissions you don't have: *, page.*", $en->get('delegation.grant', ['patterns' => '*, page.*']));
        $this->assertSame("You can't change an account with roles you can't grant.", $en->get('delegation.account'));
        $this->assertSame('At least one account must keep the right to manage staff and roles.', $en->get('delegation.last'));
    }

    public function test_every_locale_has_every_key(): void
    {
        $keys = array_keys((require dirname(__DIR__) . '/lang/en/rbac.php')['delegation']);
        foreach (['de', 'fr'] as $locale) {
            $strings = (require dirname(__DIR__) . "/lang/{$locale}/rbac.php")['delegation'];
            $this->assertSame($keys, array_keys($strings), $locale);
            $this->assertStringContainsString(':patterns', $strings['grant'], $locale);
        }
        $this->assertSame('Sie können keine Berechtigungen vergeben, die Sie selbst nicht haben: *', $this->messages('de')->get('delegation.grant', ['patterns' => '*']));
    }

    public function test_site_overrides_single_keys(): void
    {
        $dir = sys_get_temp_dir() . '/rbac_lang_' . bin2hex(random_bytes(4));
        mkdir($dir . '/en', 0777, true);
        file_put_contents($dir . '/en/rbac.php', "<?php\nreturn ['delegation' => ['last' => 'Keep one admin.']];\n");
        try {
            $en = $this->messages('en', $dir);
            $this->assertSame('Keep one admin.', $en->get('delegation.last'));
            $this->assertSame("You can't change an account with roles you can't grant.", $en->get('delegation.account'));
        } finally {
            unlink($dir . '/en/rbac.php');
            rmdir($dir . '/en');
            rmdir($dir);
        }
    }
}
