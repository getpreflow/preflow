<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Codes;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;

/** The codes admin texts (group "codes", scope admin): the same keys in en, de and fr; German in "du". */
final class CodesTranslationTest extends TestCase
{
    public function test_the_group_has_the_same_keys_in_en_de_and_fr(): void
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('codes', dirname(__DIR__, 2) . '/lang');
        $keys = array_keys($translator->entries('codes', 'en'));
        foreach (['de', 'fr'] as $locale) {
            $this->assertEqualsCanonicalizing($keys, array_keys($translator->entries('codes', $locale)), $locale);
        }
        foreach (['redemptions.title', 'redemptions.none', 'actions.revoke', 'actions.revoke_confirm', 'permissions.revoke', 'errors.purpose', 'types.code.empty'] as $key) {
            $this->assertContains('codes.' . $key, $keys, $key);
        }
        foreach ($translator->entries('codes', 'de') as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(Sie|Ihr|Ihre|Ihnen)\b/u', $text, $key);
        }
    }

    public function test_folio_declares_the_admin_group(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertSame(['path' => 'lang', 'scope' => 'admin'], $composer['extra']['preflow']['translations']['codes']);
    }
}
