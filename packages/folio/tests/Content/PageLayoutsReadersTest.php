<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Folio\Tests\Support\PageLayoutsScaffold;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\Testing\ChecksFrameBalance;

/**
 * What Folio reads and writes besides the frontend for a page's "_layout" and "_areas" (0.92): the
 * form's save (posted areas through the core's LayoutInput::merge()), the core's refusals as form
 * errors, translation status and AI translation (each area walked as a blocks field), asset usage,
 * and the side panel's change list.
 */
final class PageLayoutsReadersTest extends TestCase
{
    use ChecksFrameBalance;

    use PageLayoutsScaffold;

    private const string PHOTO = 'photo-only-in-an-area';

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_save_keeps_areas_the_form_did_not_post(): void
    {
        $this->scaffoldLayouts(publishing: true);
        $this->page('r1', 'about', 'landing', [
            'hero' => [self::hero(self::HERO, 'Welcome')],
            'main' => [self::prose(self::PROSE, ['en' => 'Before'])],
            'sidebar' => [self::prose(self::OTHER, ['en' => 'Not shown, kept'])],
        ]);

        $response = $this->post('/folio/page/r1', $this->form('About', [
            '_layout' => 'landing',
            '_areas_submitted' => '1',
            '_areas' => ['main' => ['_order' => [self::PROSE], self::PROSE => ['_block' => 'prose', 'caption' => 'After', 'body' => '']]],
        ]));

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $areas = $this->stored('page', 'r1')->get('_areas');
        $this->assertSame('After', $areas['main'][0]['caption']['en'] ?? null, 'the posted area');
        $this->assertSame([self::hero(self::HERO, 'Welcome')], $areas['hero'] ?? null, 'a placed area the form did not post');
        $this->assertSame('Not shown, kept', $areas['sidebar'][0]['caption']['en'] ?? null, 'an area the layout lacks');

        // An area posted with nothing in it empties it; a save without the layout section changes nothing.
        $this->post('/folio/page/r1', $this->form('About', ['_areas_submitted' => '1', '_areas' => ['main' => ['_order' => ['']]]]));
        $this->assertSame([], $this->stored('page', 'r1')->get('_areas')['main']);
        $this->assertSame('landing', $this->stored('page', 'r1')->get('_layout'), 'no "_layout" posted: the stored one');
        $this->post('/folio/page/r1', $this->form('Renamed'));
        $this->assertSame(['hero', 'main', 'sidebar'], array_keys($this->stored('page', 'r1')->get('_areas')));
        $this->assertSame('Renamed', $this->stored('page', 'r1')->get('title')['en']);

        // An area neither the layout has nor the page stores is refused by the core, as for an API.
        $unknown = $this->form('About', ['_areas_submitted' => '1', '_areas' => ['footer' => ['_order' => [self::OTHER], self::OTHER => ['_block' => 'prose', 'caption' => 'x']]]]);
        $this->assertSame(422, $this->post('/folio/page/r1', $unknown)->getStatusCode());
        $this->assertSame('Renamed', $this->stored('page', 'r1')->get('title')['en'], 'nothing saved');
        $drafts = $this->app()->container()->get(\Preflow\Folio\Content\DraftRecordFactory::class);
        $built = $drafts->build($this->stored('page', 'r1')->getType(), (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/')->withParsedBody($unknown), $this->stored('page', 'r1')->toArray(), 'en');
        $this->assertSame(['_areas.footer'], array_keys($drafts->buildErrors()), 'the form error, keyed as the core keys it');
        $this->assertStringContainsString('"footer"', $drafts->buildErrors()['_areas.footer'][0]);
        $this->assertSame(['hero', 'main', 'sidebar'], array_keys($built->get('_areas')), 'the stored areas, untouched');

        // The side panel names what changed since publishing.
        $this->publisher()->publish($this->stored('page', 'r1'), null);
        $this->post('/folio/page/r1', $this->form('Renamed', ['_layout' => 'standard', '_areas_submitted' => '1', '_areas' => ['main' => ['_order' => [self::PROSE], self::PROSE => ['_block' => 'prose', 'caption' => 'New', 'body' => '']]]]));
        $panel = (string) $this->get('/folio/page/r1/edit')->getBody();
        $this->assertStringContainsString('>Layout<', $panel);
        $this->assertStringContainsString('>Page areas<', $panel);
    }

    #[RunInSeparateProcess]
    public function test_area_limits_and_area_block_errors_show_as_form_errors(): void
    {
        $this->scaffoldLayouts();
        $this->page('r1', 'about', 'landing', ['hero' => [], 'main' => []]);
        $second = 'd4d4d4d4d4d4d4d4';

        $response = $this->post('/folio/page/r1', $this->form('About', [
            '_layout' => 'landing',
            '_areas_submitted' => '1',
            '_areas' => [
                'hero' => ['_order' => [self::HERO, $second], self::HERO => ['_block' => 'hero', 'headline' => 'One'], $second => ['_block' => 'hero', 'headline' => 'Two']],
                'main' => ['_order' => [self::OTHER], self::OTHER => ['_block' => 'hero', 'headline' => '']],
            ],
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->stored('page', 'r1')->get('_areas')['hero'], 'nothing saved');
        $errors = $this->errorsOfSave([
            'hero' => [self::hero(self::HERO, 'One'), self::hero($second, 'Two')],
            'main' => [self::hero(self::OTHER, '')],
        ]);
        $this->assertEqualsCanonicalizing(['_areas', '_areas.main.' . self::OTHER . '.headline'], array_keys($errors), 'the core\'s area limits and block values, keyed as the cards are');
        $this->assertStringContainsString('"hero" takes at most 1', $errors['_areas'][0]);
        $this->assertSame(['This field is required.'], $errors['_areas.main.' . self::OTHER . '.headline']);
    }

    #[RunInSeparateProcess]
    public function test_area_blocks_are_translated_like_a_blocks_field(): void
    {
        $this->scaffoldLayouts();
        $this->page('r1', 'about', 'standard', [
            'main' => [self::prose(self::PROSE, ['en' => 'Hello']), self::prose(self::OTHER, ['en' => 'Untranslated'])],
            'hero' => [self::hero(self::HERO, 'Not shown here')],
        ]);

        // Editing German: the English texts stay.
        $this->post('/folio/page/r1', $this->form('Über', ['_locale' => 'de', '_areas_submitted' => '1', '_areas' => ['main' => [
            '_order' => [self::PROSE, self::OTHER],
            self::PROSE => ['_block' => 'prose', 'caption' => 'Hallo', 'body' => ''],
            self::OTHER => ['_block' => 'prose', 'caption' => '', 'body' => ''],
        ]]]));
        $record = $this->stored('page', 'r1');
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], array_intersect_key($record->get('_areas')['main'][0]['caption'], ['en' => 1, 'de' => 1]));
        $this->assertSame('Untranslated', $record->get('_areas')['main'][1]['caption']['en']);

        $status = $this->app()->container()->get(TranslationStatus::class);
        $def = $record->getType();
        $missing = array_map(static fn ($t): string => $t->path, $status->missing($def, $record, 'de'));
        $this->assertContains('_areas.main.' . self::OTHER . '.caption', $missing, 'an untranslated area block counts');
        $this->assertNotContains('_areas.main.' . self::PROSE . '.caption', $missing);
        $this->assertNotContains('_areas.hero.' . self::HERO . '.headline', $missing, 'an area the layout does not show is not waited for');
        $this->assertTrue($status->translatable($def));
        $label = null;
        foreach ($status->texts($def, $record) as $text) {
            $label = $text->path === '_areas.main.' . self::OTHER . '.caption' ? $text->label : $label;
        }
        $this->assertSame('Main › Prose › Caption', $label);

        // AI translation fills it, as for a blocks field.
        $source = $this->app()->container()->get(TextSources::class)->get('page');
        $units = array_map(static fn ($u): string => $u->id, $source->units('r1', 'de', Scope::Needed));
        $this->assertContains('_areas.main.' . self::OTHER . '.caption', $units);
        $outcome = $source->write('r1', 'de', ['_areas.main.' . self::OTHER . '.caption' => 'Unübersetzt', 'title' => 'Über uns']);
        $this->assertSame('written', $outcome->status->value, (string) $outcome->message);
        $after = $this->stored('page', 'r1');
        $this->assertSame('Unübersetzt', $after->get('_areas')['main'][1]['caption']['de']);
        $this->assertSame('Hello', $after->get('_areas')['main'][0]['caption']['en']);
        $this->assertSame([self::hero(self::HERO, 'Not shown here')], $after->get('_areas')['hero'], 'the other areas as they were');
        $this->assertSame([], array_map(static fn ($t): string => $t->path, $status->missing($def, $after, 'de')));
        $this->assertContains('_areas.main.' . self::OTHER . '.caption', array_map(static fn ($t): string => $t->path, $status->unchecked($def, $after, 'de')), 'machine-made, not checked yet');
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    #[RunInSeparateProcess]
    #[DataProvider('storages')]
    public function test_asset_in_an_area_block_is_in_use(string $storage): void
    {
        $this->scaffoldLayouts($storage, publishing: true);
        if ($storage === 'sqlite') {
            $this->app()->container()->get(\Preflow\Folio\Storage\TableSync::class)->sync();
        }
        $this->page('r1', 'about', 'standard', [
            'main' => [],
            'hero' => [['_uid' => self::OTHER, '_block' => 'gallery', 'photos' => [self::PHOTO]]],
        ]);
        $references = $this->app()->container()->get(FolioRecordReferences::class);

        $usages = $references->usagesOf(self::PHOTO);
        $this->assertCount(1, $usages, 'in an area the layout does not show, too: switching back shows it');
        $this->assertSame(['About', '/folio/page/r1/edit', 'page'], [$usages[0]->label, $usages[0]->url, $usages[0]->type]);
        $this->assertSame([self::PHOTO => 1, 'other' => 0], $references->usageCounts([self::PHOTO, 'other']));

        $this->publisher()->publish($this->stored('page', 'r1'), null);
        $this->working('page', 'r1', ['title' => ['en' => 'About'], 'slug' => 'about', '_areas' => ['main' => []], '_version' => 2]);
        $usages = $references->usagesOf(self::PHOTO);
        $this->assertCount(1, $usages, 'still live in the published version');
        $this->assertSame(FolioRecordReferences::PUBLISHED, $usages[0]->note);
    }

    /**
     * The form's post for a page in English, with $more on top.
     *
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private function form(string $title, array $more = []): array
    {
        return $more + ['_locale' => 'en', 'title' => $title, 'slug' => 'about', 'status' => 'published'];
    }

    /**
     * The refusal of the core's write validation for the page with these areas.
     *
     * @param array<string, list<array<string, mixed>>> $areas
     * @return array<string, list<string>>
     */
    private function errorsOfSave(array $areas): array
    {
        $record = $this->stored('page', 'r1');
        $record->set('_areas', $areas);
        try {
            $this->app()->container()->get(\Preflow\Folio\Content\RecordValidator::class)->validate($record);
        } catch (\Preflow\Validation\ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
