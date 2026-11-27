<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Labels;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Labels\DataLabels;
use Preflow\Data\Labels\DeclaredLabels;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeLabels;
use Preflow\Data\TypeRegistry;

final class DeclaredLabelsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/declared_labels_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->model('plain', ['label' => 'Stellen']);
        $this->model('keyed', ['label' => 'site.types.keyed']);
        $this->model('mapped', ['label' => ['en' => 'Jobs', 'de' => 'Stellen', 'fr' => 'Emplois']]);
        $this->model('nolabel', []);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $extra */
    private function model(string $key, array $extra): void
    {
        file_put_contents($this->dir . "/{$key}.json", json_encode(['key' => $key, 'table' => $key . 's', 'storage' => 'default', 'fields' => ['name' => ['type' => 'string']]] + $extra));
    }

    private function registry(): TypeRegistry
    {
        return new TypeRegistry($this->dir);
    }

    public function test_plain_label_is_returned_as_is(): void
    {
        $labels = new DeclaredLabels($this->registry());
        $this->assertSame('Stellen', $labels->label('plain', 'en'));
        $this->assertSame('Stellen', $labels->fieldLabel(new TypeFieldDefinition('name', 'string', label: 'Stellen'), 'de'));
    }

    public function test_key_label_is_translated_with_i18n_and_kept_without(): void
    {
        $translate = static fn (string $key, string $locale): ?string => $key === 'site.types.keyed' ? "T[{$locale}]" : null;
        $this->assertSame('T[de]', (new DeclaredLabels($this->registry(), $translate))->label('keyed', 'de'));
        $this->assertSame('site.types.keyed', (new DeclaredLabels($this->registry()))->label('keyed', 'de'), 'no translator: the declared text');
        $this->assertSame('site.types.keyed', (new DeclaredLabels($this->registry(), static fn (): ?string => null))->label('keyed', 'de'), 'unknown key: the declared text');

        $field = new TypeFieldDefinition('title', 'string', label: 'site.fields.title');
        $this->assertSame('X', (new DeclaredLabels(null, static fn (): string => 'X'))->fieldLabel($field, 'en'));
        $this->assertSame('site.fields.title', (new DeclaredLabels(null))->fieldLabel($field, 'en'));
    }

    public function test_label_map_picks_locale_then_fallback_then_en(): void
    {
        $labels = new DeclaredLabels($this->registry(), null, 'fr');
        $this->assertSame('Stellen', $labels->label('mapped', 'de'));
        $this->assertSame('Emplois', $labels->label('mapped', 'it'), 'the fallback locale');
        $this->assertSame('Jobs', (new DeclaredLabels($this->registry(), null, 'it'))->label('mapped', 'es'), 'then en');
        $this->assertSame('fr', $labels->fallbackLocale());

        $field = new TypeFieldDefinition('name', 'string', label: 'Name', labels: ['en' => 'Name', 'de' => 'Bezeichnung']);
        $type = new TypeDefinition('plain', 'plains', 'default', ['name' => $field]);
        $this->assertSame('Bezeichnung', $labels->fieldLabel($field, 'de', $type));
        $this->assertSame('Name', $labels->fieldLabel($field, 'it', $type));
    }

    public function test_type_without_label_is_its_key_capitalised(): void
    {
        $this->assertSame('Nolabel', (new DeclaredLabels($this->registry()))->label('nolabel', 'en'));
        $this->assertSame('Ghost', (new DeclaredLabels($this->registry()))->label('ghost', 'en'), 'an unknown type');
        $this->assertSame('Ghost', (new DeclaredLabels(null))->label('ghost', 'en'), 'no registry');
    }

    public function test_field_without_label_is_humanized(): void
    {
        $labels = new DeclaredLabels(null);
        $this->assertSame('Possible Answers', $labels->fieldLabel(new TypeFieldDefinition('possible_answers', 'string'), 'en'));
        $this->assertSame('Hero Image', $labels->fieldLabel(new TypeFieldDefinition('heroImage', 'string'), 'en'));
    }

    public function test_bound_type_labels_win(): void
    {
        $bound = new class implements TypeLabels, \Preflow\Data\FieldLabels {
            public function label(string $type, string $locale): string { return "bound:{$type}:{$locale}"; }
            public function fieldLabel(TypeFieldDefinition $field, string $locale, ?TypeDefinition $type = null): string { return "bound-field:{$field->name}"; }
            public function fallbackLocale(): string { return 'en'; }
        };
        $labels = new DataLabels($bound, new DeclaredLabels($this->registry()));
        $this->assertSame('bound:plain:de', $labels->type('plain', 'de'));
        $this->assertSame('bound-field:name', $labels->field(new TypeFieldDefinition('name', 'string'), 'de'));

        $typesOnly = new class implements TypeLabels {
            public function label(string $type, string $locale): string { return 'T'; }
        };
        $labels = new DataLabels($typesOnly, new DeclaredLabels($this->registry()));
        $this->assertSame('T', $labels->type('plain', 'de'));
        $this->assertSame('Name', $labels->field(new TypeFieldDefinition('name', 'string'), 'de'), 'TypeLabels without FieldLabels: declared field label');

        $this->assertSame('Stellen', (new DataLabels(null, new DeclaredLabels($this->registry())))->type('plain', 'de'));
    }

    public function test_data_manager_gives_the_declared_labels_and_takes_a_translator(): void
    {
        $dm = new DataManager(drivers: [], typeRegistry: $this->registry());
        $this->assertSame('Stellen', $dm->labels()->type('plain', 'en'));
        $this->assertSame('site.types.keyed', $dm->labels()->type('keyed', 'en'));

        $dm->translateLabelsWith(static fn (string $key, string $locale): ?string => "{$key}@{$locale}", 'de');
        $this->assertSame('site.types.keyed@fr', $dm->labels()->type('keyed', 'fr'));

        $dm->labelsFrom(static fn (): TypeLabels => new class implements TypeLabels {
            public function label(string $type, string $locale): string { return 'bound'; }
        });
        $this->assertSame('bound', $dm->labels()->type('plain', 'en'));
    }
}
