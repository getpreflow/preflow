<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelLabelKeys;
use Preflow\Data\TypeRegistry;

/**
 * Labels and help texts in a model file are a plain string or a map per language. The
 * definition keeps one default text (label/help: the "en" entry, else the first) and the
 * whole map (labels/helps); select options keep their shape. ModelLabelKeys names the keys
 * a language file translates them with.
 */
final class TypeRegistryLabelsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typelabels_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/app.json', json_encode([
            'key' => 'app', 'table' => 'app', 'storage' => 'json', 'id_field' => 'uuid',
            'label' => ['en' => 'Apps', 'de' => 'Anwendungen'],
            'label_singular' => ['en' => 'App', 'de' => 'Anwendung'],
            'section' => 'tools',
            'fields' => [
                'title' => ['type' => 'string', 'label' => ['de' => 'Titel', 'fr' => 'Titre'], 'help' => ['en' => 'Shown in lists', 'de' => 'In Listen']],
                'plain' => ['type' => 'string', 'label' => 'Plain', 'help' => 'Old help'],
                'bare' => ['type' => 'string'],
                'kind' => ['type' => 'select', 'label' => 'Kind', 'options' => ['web' => ['en' => 'Web', 'de' => 'Netz'], 'cli' => 'CLI', 'odd.value' => 'Odd']],
                'tags' => ['type' => 'select', 'options' => ['red', 'blue']],
                'secret' => ['type' => 'string', 'label' => 'Secret', 'hidden' => true],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/app.json');
        @rmdir($this->dir);
    }

    public function test_a_map_label_gives_the_english_entry_else_the_first_and_keeps_the_map(): void
    {
        $title = (new TypeRegistry($this->dir))->get('app')->fields['title'];

        $this->assertSame('Titel', $title->label, 'no "en" entry: the first');
        $this->assertSame(['de' => 'Titel', 'fr' => 'Titre'], $title->labels);
        $this->assertSame('Shown in lists', $title->help);
        $this->assertSame(['en' => 'Shown in lists', 'de' => 'In Listen'], $title->helps);
        $this->assertSame([], $title->config);
    }

    public function test_plain_string_labels_are_unchanged(): void
    {
        $fields = (new TypeRegistry($this->dir))->get('app')->fields;

        $this->assertSame('Plain', $fields['plain']->label);
        $this->assertSame('Old help', $fields['plain']->help);
        $this->assertSame([], $fields['plain']->labels);
        $this->assertSame([], $fields['plain']->helps);
        $this->assertNull($fields['bare']->label);
        $this->assertNull($fields['bare']->help);
    }

    public function test_select_options_keep_their_map_shape_and_config_gets_no_labels(): void
    {
        $kind = (new TypeRegistry($this->dir))->get('app')->fields['kind'];

        $this->assertSame(['options' => ['web' => ['en' => 'Web', 'de' => 'Netz'], 'cli' => 'CLI', 'odd.value' => 'Odd']], $kind->config);
        $this->assertArrayNotHasKey('label', $kind->config);
        $this->assertArrayNotHasKey('labels', $kind->config);
        $this->assertArrayNotHasKey('help', $kind->config);
    }

    public function test_the_keys_a_language_file_translates_a_model_with(): void
    {
        $this->assertSame('site.types.app.label', ModelLabelKeys::type('site', 'app'));
        $this->assertSame('site.types.app.singular', ModelLabelKeys::singular('site', 'app'));
        $this->assertSame('codes.fields.code.purpose.label', ModelLabelKeys::field('codes', 'code', 'purpose'));
        $this->assertSame('codes.fields.code.purpose.help', ModelLabelKeys::help('codes', 'code', 'purpose'));
        $this->assertSame('codes.options.code.form.readable', ModelLabelKeys::option('codes', 'code', 'form', 'readable'));
        $this->assertNull(ModelLabelKeys::option('codes', 'code', 'form', 'odd.value'), 'a value with a dot has no key');
        $this->assertSame('site.sections.tools', ModelLabelKeys::section('site', 'tools'));
    }

    public function test_derive_lists_every_key_of_a_model_with_the_text_the_file_gives(): void
    {
        $schema = json_decode((string) file_get_contents($this->dir . '/app.json'), true);

        $this->assertSame([
            'site.types.app.label' => ['en' => 'Apps', 'de' => 'Anwendungen'],
            'site.types.app.singular' => ['en' => 'App', 'de' => 'Anwendung'],
            'site.fields.app.title.label' => ['de' => 'Titel', 'fr' => 'Titre'],
            'site.fields.app.title.help' => ['en' => 'Shown in lists', 'de' => 'In Listen'],
            'site.fields.app.plain.label' => 'Plain',
            'site.fields.app.plain.help' => 'Old help',
            'site.fields.app.bare.label' => null,
            'site.fields.app.kind.label' => 'Kind',
            'site.options.app.kind.web' => ['en' => 'Web', 'de' => 'Netz'],
            'site.options.app.kind.cli' => 'CLI',
            'site.fields.app.tags.label' => null,
            'site.options.app.tags.red' => 'red',
            'site.options.app.tags.blue' => 'blue',
        ], ModelLabelKeys::derive($schema, 'site'));
    }
}
