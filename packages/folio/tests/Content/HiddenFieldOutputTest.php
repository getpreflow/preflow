<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\FieldVisibility;
use Preflow\Data\Search\RecordSearch;
use Preflow\Folio\Content\Collections;
use Preflow\Folio\Content\ListCells;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\Singletons;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\Testing\ChecksFrameBalance;

/**
 * A field its show_when hides reads as empty (null) in every output layer (rendered values,
 * folio_get/folio_list/folio_find, the page template's record, list cells, search, translation
 * status) while the stored value stays: get() and the transfer export give it unchanged.
 */
final class HiddenFieldOutputTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private const array FIELDS = [
        'kind' => ['type' => 'string'],
        'slug' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'title' => ['type' => 'string', 'translatable' => true],
        'button_url' => ['type' => 'string', 'translatable' => true, 'show_when' => ['field' => 'kind', '=' => 'link']],
    ];

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('teaser', ['label' => 'Teasers', 'publishing' => false, 'frontend' => ['route' => '/teasers/{slug}'], 'fields' => self::FIELDS]);
        $this->writeModel('banner', ['label' => 'Banner', 'publishing' => false, 'singleton' => true, 'fields' => self::FIELDS]);
        $this->write('resources/folio/frontend/teaser.twig', '<p class="r">[{{ record.button_url ?? "none" }}]</p><p class="m">[{{ rendered.button_url ?? "none" }}]</p><h1>{{ record.title }}</h1>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private const array HIDDEN = [
        'kind' => 'text',
        'slug' => 'one',
        'status' => 'published',
        'title' => ['en' => 'Teaser one'],
        'button_url' => ['en' => 'secretpath'],
        '_locales' => ['en'],
    ];

    #[RunInSeparateProcess]
    public function test_hidden_field_reads_empty_in_output_but_exports_stored(): void
    {
        $this->dm()->saveType(\Preflow\Data\DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('teaser'), ['uuid' => 't1'] + self::HIDDEN), validate: false);
        $this->dm()->saveType(\Preflow\Data\DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('banner'), ['uuid' => 'banner'] + self::HIDDEN), validate: false);
        $app = $this->app();
        $c = $app->container();
        $record = $this->stored('teaser', 't1');
        $def = $record->getType();

        // The record keeps the stored value.
        $this->assertSame(['en' => 'secretpath'], $record->get('button_url'));

        // Rendered values and the templates' values.
        $rendered = $c->get(RecordRenderer::class)->renderedMap($record, 'en');
        $this->assertArrayHasKey('button_url', $rendered);
        $this->assertNull($rendered['button_url']);
        $this->assertSame('Teaser one', $rendered['title']);
        $banner = $c->get(Singletons::class)->get('banner') ?? [];
        $this->assertArrayHasKey('button_url', $banner);
        $this->assertNull($banner['button_url'], 'folio_get');
        $this->assertSame('Teaser one', $banner['title'] ?? null);
        $listed = $c->get(Collections::class)->list('teaser')[0] ?? [];
        $this->assertArrayHasKey('button_url', $listed);
        $this->assertNull($listed['button_url'], 'folio_list');
        $found = $c->get(Collections::class)->find('teaser', 't1') ?? [];
        $this->assertArrayHasKey('button_url', $found);
        $this->assertNull($found['button_url'], 'folio_find');
        $this->assertSame('text', $found['kind'] ?? null);

        // The page template: record and rendered both read the field as empty.
        $page = (string) $this->get('/teasers/one')->getBody();
        $this->assertStringContainsString('<h1>Teaser one</h1>', $page);
        $this->assertStringContainsString('<p class="r">[none]</p>', $page);
        $this->assertStringContainsString('<p class="m">[none]</p>', $page);
        $this->assertStringNotContainsString('secretpath', $page);

        // A list cell (and the list's Excel export, which writes the cells).
        $fieldTypes = $c->get(FieldTypeRegistry::class);
        $this->assertSame('', ListCells::text($fieldTypes, $def, 'button_url', $record));
        $this->assertSame('Teaser one', ListCells::text($fieldTypes, $def, 'title', $record));

        // Search finds nothing through the hidden text, the record through a shown field.
        $search = $c->get(RecordSearch::class);
        $this->assertSame([], $search->search('secretpath', ['teaser']));
        $this->assertCount(1, $search->search('teaser', ['teaser']));

        // Translation status: the hidden text is not one to translate (nor sent to a machine).
        $translations = $c->get(TranslationStatus::class);
        $this->assertSame(['title'], array_map(static fn (TranslatableText $t): string => $t->path, $translations->texts($def, $record)));
        $state = $translations->state($def, $record, 'de');
        $this->assertSame([1, 1], [$state->total, $state->missing]);

        // An API resource reads the same way: JSON with null.
        $this->assertSame('{"kind":"text","button_url":null}', json_encode(array_intersect_key(FieldVisibility::visibleValues($def, $record->localized('en')->toArray()), ['kind' => 1, 'button_url' => 1])));

        // The transfer export carries the stored value.
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        (new Exporter(ContentSet::fromApp($app), $c->get(\Preflow\Data\DataManager::class)))->export($stream, 'data');
        rewind($stream);
        $this->assertStringContainsString('secretpath', (string) stream_get_contents($stream));

        // Switched to "link": the stored value shows everywhere again.
        $shown = $this->stored('teaser', 't1');
        $shown->set('kind', 'link');
        $this->dm()->saveType($shown, validate: false);
        $shown = $this->stored('teaser', 't1');
        $c = $this->app()->container();
        $this->assertSame('secretpath', $c->get(RecordRenderer::class)->renderedMap($shown, 'en')['button_url']);
        $this->assertSame('secretpath', ListCells::text($c->get(FieldTypeRegistry::class), $def, 'button_url', $shown));
        $this->assertCount(1, $c->get(RecordSearch::class)->search('secretpath', ['teaser']));
        $this->assertSame(['title', 'button_url'], array_map(static fn (TranslatableText $t): string => $t->path, $c->get(TranslationStatus::class)->texts($def, $shown)));
        $this->assertStringContainsString('<p class="r">[secretpath]</p>', (string) $this->get('/teasers/one')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_hidden_field_keeps_its_translation_stamps(): void
    {
        $c = $this->app()->container();
        $translations = $c->get(TranslationStatus::class);
        $def = $this->dm()->getTypeRegistry()->get('teaser');
        $before = \Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => 't1', 'kind' => 'text', 'title' => ['en' => 'T', 'de' => 'T'], 'button_url' => ['en' => 'a', 'de' => 'b'],
            TranslationStatus::KEY => ['de' => ['button_url' => $translations->fingerprint('a'), 'title' => $translations->fingerprint('T')]]]);
        $after = clone $before;
        $after->set('title', ['en' => 'T', 'de' => 'Tee']);

        $stamped = $translations->stamp($def, $before, $after, 'de');

        // Hidden now, so not a text of the record; its stamp stays for when it shows again.
        $this->assertSame($translations->fingerprint('a'), $translations->stampedFingerprint($stamped, 'de', 'button_url'));
    }
}
