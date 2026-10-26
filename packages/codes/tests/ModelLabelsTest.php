<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelLabelKeys;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;

/**
 * The labels of the code model (type, fields, help texts, options) are keys of the admin
 * group "codes" (lang/{en,de,fr}/codes.php), declared in composer.json and named by the
 * extension, so Folio shows them in the admin's language.
 */
final class ModelLabelsTest extends TestCase
{
    public function test_the_package_declares_the_admin_group_codes_and_the_extension_uses_it(): void
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

        $this->assertSame('admin', $registry->scope('codes'));
        $meta = (new \ReflectionClass(\Preflow\Codes\Extension::class))->getAttributes(FolioExtension::class)[0]->newInstance();
        $this->assertSame('codes', $meta->translations);
    }

    public function test_every_label_of_the_model_has_a_text_in_en_de_and_fr(): void
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('codes', dirname(__DIR__) . '/lang');
        $keys = ModelLabelKeys::derive(json_decode((string) file_get_contents(dirname(__DIR__) . '/src/models/code.json'), true), 'codes');

        $this->assertArrayHasKey('codes.fields.code.purpose.label', $keys);
        $this->assertArrayNotHasKey('codes.fields.code.payload.label', $keys, 'hidden');
        foreach (['en', 'de', 'fr'] as $locale) {
            foreach (array_keys($keys) as $key) {
                $this->assertTrue($translator->has($key, $locale), "{$key} in {$locale}");
            }
        }
        $this->assertSame('Zweck', $translator->get('codes.fields.code.purpose.label', [], 'de'));
        $this->assertEqualsCanonicalizing(array_keys($translator->entries('codes', 'en')), array_keys($translator->entries('codes', 'de')));
        $this->assertEqualsCanonicalizing(array_keys($translator->entries('codes', 'en')), array_keys($translator->entries('codes', 'fr')));
    }
}
