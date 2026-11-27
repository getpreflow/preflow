<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PageLayoutsScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;

/**
 * The page layout editor in Folio (0.92): the layout picker as the form's first control (or "Layout:
 * Home (fixed)" for a pinned page), the layout's areas as block lists after the type's fields, the
 * switch and move endpoint POST /folio/_areas (the areas from the posted form, nothing stored), the
 * panel "Not shown in this layout", and the core's refusals shown where the editor works. The
 * forms are posted as a browser posts them: every control of the record form, read from the
 * rendered page (and after a switch, from the page with the answer swapped in, as htmx does).
 */
final class PageLayoutEditorTest extends TestCase
{
    use ChecksFrameBalance;

    use PageLayoutsScaffold;

    protected function setUp(): void
    {
        $this->scaffoldLayouts(publishing: true);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_new_page_form_asks_for_the_layout_with_the_default_chosen(): void
    {
        $form = $this->html($this->get('/folio/page/new'));

        $select = $this->between($form, 'name="_layout"', '</select>');
        $this->assertStringContainsString('<option value="landing">Landing page</option>', $select);
        $this->assertStringContainsString('<option value="standard" selected>Standard</option>', $select, 'the type\'s default, preselected');
        $this->assertStringNotContainsString('value="home"', $select, 'a pinned layout is no choice');
        $this->assertLessThan(strpos($form, 'name="title"'), strpos($form, 'name="_layout"'), 'the first control, above the fields');
        $this->assertStringContainsString('>Layout</label>', $form);

        $body = self::posted($form);
        $this->assertSame('standard', $body['_layout']);
        $this->assertSame('1', $body['_areas_submitted'] ?? null);
        $this->assertSame(['main'], array_keys($body['_areas']), 'the default layout\'s areas');
        $this->assertStringContainsString('/folio/_blocks/prose/new?name=_areas%5Bmain%5D', $form, 'the area\'s picker');

        // Creating the page stores the layout chosen.
        $body['title'] = 'About';
        $body['slug'] = 'about';
        $body['_layout'] = 'landing';
        $this->assertSame(302, $this->post('/folio/page', $body)->getStatusCode());
        $created = $this->dm()->queryType('page')->first();
        $this->assertSame('landing', $created?->get('_layout'));
    }

    #[RunInSeparateProcess]
    public function test_form_shows_areas_in_layout_order_after_the_fields(): void
    {
        $this->page('r1', 'about', 'landing', [
            'main' => [self::prose(self::PROSE, ['en' => 'In main'])],
            'hero' => [self::hero(self::HERO, 'Welcome')],
        ]);

        $form = $this->html($this->get('/folio/page/r1/edit'));

        $hero = strpos($form, 'name="_areas[hero][_order][]"');
        $main = strpos($form, 'name="_areas[main][_order][]"');
        $this->assertNotFalse($hero);
        $this->assertNotFalse($main);
        $this->assertLessThan($hero, strpos($form, 'name="status"'), 'the areas follow the type\'s fields');
        $this->assertLessThan($main, $hero, 'in the layout\'s order (hero, main), not the stored one');
        $this->assertStringContainsString('>Page areas</h2>', $form);
        $this->assertStringContainsString('value="Welcome"', $form, 'the hero block\'s card');
        $this->assertStringContainsString('value="In main"', $form);
        $this->assertStringContainsString('<option value="landing" selected>', $form);
        $this->assertStringNotContainsString('Not shown in this layout', $form);

        // As the browser posts it, saving changes nothing.
        $before = $this->stored('page', 'r1')->get('_areas');
        $this->assertSame(302, $this->post('/folio/page/r1', self::posted($form))->getStatusCode());
        $this->assertSame($before, $this->stored('page', 'r1')->get('_areas'));
    }

    #[RunInSeparateProcess]
    public function test_switch_renders_the_new_areas_and_the_panel(): void
    {
        $this->page('r1', 'about', 'landing', [
            'hero' => [self::hero(self::HERO, 'Welcome')],
            'main' => [self::prose(self::PROSE, ['en' => 'In main'])],
        ]);
        $form = $this->html($this->get('/folio/page/r1/edit'));

        $answer = $this->switchTo($form, 'standard', 'r1');

        $this->assertStringContainsString('data-areas-section', $answer);
        $this->assertStringContainsString('value="In main"', $answer, 'main stays');
        $panel = $this->between($answer, 'data-areas-panel', '</section>');
        $this->assertStringContainsString('Not shown in this layout', $panel);
        $this->assertStringContainsString('These blocks are kept, but the page does not show them.', $panel);
        $this->assertStringContainsString('name="_areas[hero][_order][]" value="' . self::HERO . '"', $panel, 'the hero block, as a card');
        $this->assertStringContainsString('value="Welcome"', $panel);
        $this->assertStringContainsString('<span class="form-label">Hero <span', $panel, 'labelled');
        $this->assertStringContainsString('name="_areas_move" value="' . self::HERO . ':main"', $panel, 'movable to the new layout\'s area');
        $this->assertStringContainsString('>Move</span>', $panel);
        $this->assertStringNotContainsString('new?name=_areas%5Bhero%5D&', $panel, 'nothing is added to a hidden area');
        $this->assertStringNotContainsString('name="_areas[hero][_order][]" value="' . self::HERO . '"', $this->before($answer, 'data-areas-panel'));
        $this->assertSame('landing', $this->stored('page', 'r1')->get('_layout'), 'nothing stored');
        $this->assertStringContainsString('hx-swap-oob="true"', $answer, 'the notice, cleared by a switch');
    }

    #[RunInSeparateProcess]
    public function test_switching_away_and_back_restores_area_blocks(): void
    {
        $areas = [
            'hero' => [self::hero(self::HERO, 'Welcome')],
            'main' => [self::prose(self::PROSE, ['en' => 'In main'])],
        ];
        $this->page('r1', 'about', 'landing', $areas);
        $this->publisher()->publish($this->stored('page', 'r1'), null);
        $form = $this->html($this->get('/folio/page/r1/edit'));

        // Switch to "Standard" and autosave, as the editor does.
        $switched = self::swapped($form, $this->switchTo($form, 'standard', 'r1'), 'standard');
        $saved = $this->post('/folio/page/r1/autosave', self::posted($switched));
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertStringNotContainsString('Not saved', (string) $saved->getBody());
        $stored = $this->stored('page', 'r1');
        $this->assertSame('standard', $stored->get('_layout'));
        $this->assertSame($areas['hero'], $stored->get('_areas')['hero'], 'the hero blocks kept, unchanged');
        $this->publisher()->publish($stored, null);
        $this->assertStringNotContainsString('Welcome', (string) $this->get('/p/about')->getBody(), 'and never rendered');

        // Reload: the hero blocks wait in the panel; switch back: they are in the hero area again.
        $reloaded = $this->html($this->get('/folio/page/r1/edit'));
        $this->assertStringContainsString('<option value="standard" selected>', $reloaded);
        $this->assertStringContainsString('name="_areas[hero][_order][]" value="' . self::HERO . '"', $this->between($reloaded, 'data-areas-panel', '</section>'));
        $back = $this->switchTo($reloaded, 'landing', 'r1');
        $this->assertStringNotContainsString('data-areas-panel', $back);
        $this->assertLessThan(strpos($back, 'name="_areas[main][_order][]"'), strpos($back, 'name="_areas[hero][_order][]" value="' . self::HERO . '"'), 'in the hero area, before main');

        $this->assertSame(302, $this->post('/folio/page/r1', self::posted(self::swapped($reloaded, $back, 'landing')))->getStatusCode());
        $this->assertSame('landing', $this->stored('page', 'r1')->get('_layout'));
        $this->assertSame($areas, $this->stored('page', 'r1')->get('_areas'), 'every block as it was');
        $this->publisher()->publish($this->stored('page', 'r1'), null);
        $this->assertStringContainsString('<h1>Welcome</h1>', (string) $this->get('/p/about')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_new_page_switched_away_from_a_filled_area_saves_it_unplaced(): void
    {
        $form = $this->html($this->get('/folio/page/new'));
        $landing = self::swapped($form, $this->areas($form, '', ['_areas_to' => 'landing', '_layout' => 'landing']), 'landing');
        $body = self::posted($landing);
        $body['_areas']['hero'] = ['_order' => ['', self::HERO], self::HERO => ['_block' => 'hero', 'headline' => 'Welcome', 'intro' => '']];
        $body['title'] = 'About';
        $body['slug'] = 'about';

        // Switched to "Standard" before the page was ever saved: the hero waits in the panel.
        $answer = $this->post('/folio/_areas?' . http_build_query(['name' => '_areas', 'type' => 'page', 'locale' => 'en', 'depth' => '0']), ['_areas_to' => 'standard', '_layout' => 'standard'] + $body);
        $switched = self::swapped($landing, $this->html($answer), 'standard');
        $this->assertStringContainsString('value="Welcome"', $this->between($switched, 'data-areas-panel', '</section>'));

        $saved = $this->post('/folio/page', self::posted($switched));
        $this->assertSame(302, $saved->getStatusCode(), substr((string) $saved->getBody(), 0, 3000));
        $created = $this->dm()->queryType('page')->first();
        $this->assertSame('standard', $created?->get('_layout'));
        $this->assertSame('Welcome', $created?->get('_areas')['hero'][0]['headline']['en'] ?? null, 'kept, not shown in this layout');
    }

    #[RunInSeparateProcess]
    public function test_move_puts_the_block_in_the_chosen_area(): void
    {
        $this->page('r1', 'about', 'standard', [
            'main' => [self::prose(self::PROSE, ['en' => 'In main'])],
            'hero' => [self::hero(self::HERO, 'Welcome')],
        ]);
        $form = $this->html($this->get('/folio/page/r1/edit'));
        $this->assertStringContainsString('Not shown in this layout', $form);

        $answer = $this->areas($form, 'r1', ['_areas_move' => self::HERO . ':main']);

        $this->assertStringNotContainsString('data-areas-panel', $answer, 'the panel is empty: gone');
        $main = $this->after($answer, 'name="_areas[main][_order][]" value=""');
        $this->assertLessThan(strpos($main, 'value="' . self::HERO . '"'), strpos($main, 'value="' . self::PROSE . '"'), 'at the end of main');
        $this->assertSame([], $this->stored('page', 'r1')->get('_areas')['main'][0]['quotes'], 'nothing stored yet');

        $this->assertSame(302, $this->post('/folio/page/r1', self::posted(self::swapped($form, $answer)))->getStatusCode());
        $stored = $this->stored('page', 'r1')->get('_areas');
        $this->assertSame([self::PROSE, self::HERO], array_column($stored['main'], '_uid'));
        $this->assertSame('Welcome', $stored['main'][1]['headline']['en']);
        $this->assertSame([], $stored['hero']);
    }

    #[RunInSeparateProcess]
    public function test_remove_is_the_only_way_to_drop_a_panel_block(): void
    {
        $hero = [self::hero(self::HERO, 'Welcome'), self::hero(self::OTHER, 'Second')];
        $this->page('r1', 'about', 'standard', ['main' => [], 'hero' => $hero]);
        $form = $this->html($this->get('/folio/page/r1/edit'));

        // Saving the form as it is, or without its layout section, keeps the panel's blocks.
        $this->assertSame(302, $this->post('/folio/page/r1', self::posted($form))->getStatusCode());
        $this->assertSame($hero, $this->stored('page', 'r1')->get('_areas')['hero']);
        $body = self::posted($this->edit('r1'));
        unset($body['_areas'], $body['_areas_submitted'], $body['_layout']);
        $this->assertSame(302, $this->post('/folio/page/r1', $body)->getStatusCode());
        $this->assertSame($hero, $this->stored('page', 'r1')->get('_areas')['hero']);
        $body = self::posted($this->edit('r1'));
        unset($body['_areas']['hero']);
        $this->assertSame(302, $this->post('/folio/page/r1', $body)->getStatusCode());
        $this->assertSame($hero, $this->stored('page', 'r1')->get('_areas')['hero'], 'an area not posted keeps its blocks');

        // Remove (the card's menu takes the card out of the form): that block goes, the other stays.
        $form = $this->edit('r1');
        $this->assertStringContainsString('data-block-remove', $this->between($form, 'id="block-' . self::HERO . '"', 'class="folio-block-body"'));
        $this->assertSame(302, $this->post('/folio/page/r1', self::posted(self::without($form, 'block-' . self::HERO)))->getStatusCode());
        $this->assertSame([$hero[1]], $this->stored('page', 'r1')->get('_areas')['hero']);
        $this->assertSame(302, $this->post('/folio/page/r1', self::posted(self::without($this->edit('r1'), 'block-' . self::OTHER)))->getStatusCode());
        $this->assertSame([], $this->stored('page', 'r1')->get('_areas')['hero'], 'the last one too');
    }

    #[RunInSeparateProcess]
    public function test_pinned_page_shows_fixed_and_no_picker(): void
    {
        $this->page('r1', 'home', 'standard', ['top' => [self::prose(self::PROSE, ['en' => 'On top'])], 'main' => [self::prose(self::OTHER, ['en' => 'Kept'])]]);

        $form = $this->html($this->get('/folio/page/r1/edit'));

        $this->assertStringContainsString('Layout: Home (fixed)', $form);
        $this->assertStringContainsString('Set by "layouts.pinned" in the type\'s configuration.', $form);
        $this->assertStringNotContainsString('name="_layout"', $form, 'no switch');
        $this->assertStringContainsString('name="_areas[top][_order][]"', $form, 'the pin\'s areas');
        $this->assertStringContainsString('value="On top"', $form);
        $this->assertStringContainsString('Not shown in this layout', $form, 'the stored layout\'s area waits in the panel');

        $this->assertSame(302, $this->post('/folio/page/r1', self::posted($form))->getStatusCode());
        $this->assertSame('standard', $this->stored('page', 'r1')->get('_layout'), 'the stored key stays; the pin decides');
    }

    #[RunInSeparateProcess]
    public function test_renaming_a_pinned_page_shows_the_refusal_on_the_slug_field(): void
    {
        $this->page('r1', 'home', null, ['top' => []]);
        $body = self::posted($this->html($this->get('/folio/page/r1/edit')));
        $body['slug'] = 'start';

        $response = $this->post('/folio/page/r1', $body);

        $this->assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        $slug = $this->after($html, 'name="slug"');
        $message = '<div class="form-error">This page is pinned to the layout "home" by the entry "home" of "layouts.pinned" in the type "page". Change or remove that entry first, then rename the page.</div>';
        $this->assertStringContainsString($message, substr($slug, 0, 1000), 'beside the slug field');
        $this->assertStringContainsString('value="start"', substr($slug, 0, 100), 'with the name as typed');
        $this->assertSame('home', $this->stored('page', 'r1')->get('slug'));
    }

    #[RunInSeparateProcess]
    public function test_unavailable_layout_shows_the_notice_and_preselects_the_fallback(): void
    {
        $this->page('r1', 'about', 'gone', [
            'main' => [self::prose(self::PROSE, ['en' => 'In main'])],
            'sidebar' => [self::prose(self::OTHER, ['en' => 'Kept'])],
        ]);

        $form = $this->html($this->get('/folio/page/r1/edit'));

        $this->assertStringContainsString('The layout &quot;gone&quot; is no longer available. This page shows as &quot;Standard&quot; until you choose a layout.', $form);
        $select = $this->between($form, 'name="_layout"', '</select>');
        $this->assertStringContainsString('<option value="gone" selected>Standard (until you choose a layout)</option>', $select, 'the fallback shown, the stored key kept');
        $this->assertStringContainsString('<option value="standard">Standard</option>', $select, 'and the fallback as a choice of its own');
        $this->assertStringContainsString('name="_areas[sidebar][_order][]" value="' . self::OTHER . '"', $this->between($form, 'data-areas-panel', '</section>'));
        $this->assertLessThan(strpos($form, 'name="title"'), strpos($form, 'no longer available'), 'beside the picker');

        // A move keeps the notice (nothing chosen yet); a switch clears it.
        $moved = $this->areas($form, 'r1', ['_areas_move' => self::OTHER . ':main']);
        $this->assertStringContainsString('no longer available', $moved);
        $switched = $this->switchTo($form, 'landing', 'r1');
        $this->assertMatchesRegularExpression('~<div class="folio-notice folio-layout-notice" id="[^"]+-notice" role="status" hidden hx-swap-oob="true"></div>~', $switched);
    }

    #[RunInSeparateProcess]
    public function test_saving_as_shown_keeps_a_removed_layout_until_one_is_chosen(): void
    {
        $this->page('r1', 'about', 'gone', ['main' => [], 'sidebar' => [self::prose(self::OTHER, ['en' => 'Kept'])]]);
        $reported = fn (): bool => str_contains(implode("\n", $this->report()), 'the layout "gone"');
        $this->assertTrue($reported(), 'db:check names the page');

        // An unrelated edit, saved and autosaved: the stored key stays, and so do the notice and the report.
        $body = self::posted($this->edit('r1'));
        $body['title'] = 'Renamed';
        $this->assertSame(302, $this->post('/folio/page/r1', $body)->getStatusCode());
        $this->assertSame('gone', $this->stored('page', 'r1')->get('_layout'));
        $body = self::posted($this->edit('r1'));
        $body['title'] = 'Autosaved';
        $saved = $this->post('/folio/page/r1/autosave', $body);
        $this->assertStringNotContainsString('Not saved', (string) $saved->getBody());
        $this->assertSame('Autosaved', $this->stored('page', 'r1')->get('title')['en']);
        $this->assertSame('gone', $this->stored('page', 'r1')->get('_layout'), 'no write: nothing was chosen');
        $this->assertStringContainsString('is no longer available', $this->edit('r1'));
        $this->assertTrue($reported(), 'still reported');

        // Choosing the layout it showed, explicitly, writes it.
        $form = $this->edit('r1');
        $chosen = self::swapped($form, $this->switchTo($form, 'standard', 'r1'), 'standard');
        $this->assertSame(302, $this->post('/folio/page/r1', self::posted($chosen))->getStatusCode());
        $this->assertSame('standard', $this->stored('page', 'r1')->get('_layout'));
        $this->assertStringNotContainsString('is no longer available', $this->edit('r1'));
        $this->assertFalse($reported(), 'no longer on a missing layout');
        $this->assertSame('Kept', $this->stored('page', 'r1')->get('_areas')['sidebar'][0]['caption']['en']);
    }

    /** @return list<string> the page layout report's lines (what `preflow db:check` prints) */
    private function report(): array
    {
        $c = $this->app()->container();

        return (new \Preflow\Blocks\Layouts\LayoutReport(
            $c->get(\Preflow\Blocks\Layouts\PageLayouts::class),
            $c->get(\Preflow\Blocks\Layouts\LayoutRegistry::class),
            $c->get(\Preflow\Data\DataManager::class)->getTypeRegistry(),
            $c->get(\Preflow\Data\DataManager::class),
        ))->lines();
    }

    #[RunInSeparateProcess]
    public function test_new_block_and_duplicate_work_inside_an_area(): void
    {
        $this->page('r1', 'about', 'landing', ['hero' => [self::hero(self::HERO, 'Welcome')], 'main' => []]);

        $new = $this->get('/folio/_blocks/prose/new?name=' . rawurlencode('_areas[main]'));
        $this->assertSame(200, $new->getStatusCode(), (string) $new->getBody());
        $card = (string) $new->getBody();
        $this->assertMatchesRegularExpression('~name="_areas\[main\]\[([a-f0-9]{16})\]\[_block\]" value="prose"~', $card);
        preg_match('~data-block-uid="([a-f0-9]{16})"~', $card, $m);
        $this->assertStringContainsString('/folio/_blocks/quote/new?name=' . rawurlencode('_areas[main][' . $m[1] . '][quotes]'), $card, 'a nested list inside an area block');
        $nested = $this->get('/folio/_blocks/quote/new?name=' . rawurlencode('_areas[main][' . $m[1] . '][quotes]') . '&depth=1');
        $this->assertSame(200, $nested->getStatusCode());
        $this->assertStringContainsString('name="_areas[main][' . $m[1] . '][quotes][', (string) $nested->getBody());
        $this->assertSame(400, $this->get('/folio/_blocks/prose/new?name=' . rawurlencode('_areas[main][x]'))->getStatusCode(), 'names stay checked');

        $body = self::posted($this->html($this->get('/folio/page/r1/edit')));
        $copy = $this->post('/folio/_blocks/duplicate?' . http_build_query(['name' => '_areas[hero]', 'uid' => self::HERO, 'type' => 'page', 'id' => 'r1', 'locale' => 'en']), $body);
        $this->assertSame(200, $copy->getStatusCode(), (string) $copy->getBody());
        $html = (string) $copy->getBody();
        $this->assertMatchesRegularExpression('~name="_areas\[hero\]\[_order\]\[\]" value="(?!' . self::HERO . ')[a-f0-9]{16}"~', $html, 'a fresh uid');
        $this->assertStringContainsString('value="Welcome"', $html);
    }

    #[RunInSeparateProcess]
    public function test_area_picker_offers_only_the_areas_blocks(): void
    {
        $this->write('config/blocks.php', str_replace('Landing::class,', 'Landing::class, \\Preflow\\Folio\\Tests\\Fixtures\\Layouts\\Wide::class,', (string) file_get_contents($this->dir . '/config/blocks.php')));
        $model = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $model['layouts']['allowed'][] = 'wide';
        $this->writeModel('page', array_diff_key($model, array_flip(['key', 'table', 'storage', 'id_field'])));
        $this->page('r1', 'about', 'landing', ['hero' => [], 'main' => []]);

        $form = $this->html($this->get('/folio/page/r1/edit'));
        $hero = $this->between($form, 'name="_areas[hero][_order][]"', 'name="_areas[main][_order][]"');
        $this->assertStringContainsString('/folio/_blocks/hero/new?name=_areas%5Bhero%5D', $hero, '"allowed": ["hero"]');
        $this->assertStringNotContainsString('/folio/_blocks/prose/new', $hero);
        $main = $this->after($form, 'name="_areas[main][_order][]"');
        $this->assertStringContainsString('/folio/_blocks/hero/new?name=_areas%5Bmain%5D', $main, 'no "allowed": every block');
        $this->assertStringContainsString('/folio/_blocks/prose/new?name=_areas%5Bmain%5D', $main);

        $wide = $this->switchTo($form, 'wide', 'r1');
        $this->assertStringContainsString('/folio/_blocks/prose/new?name=_areas%5Bmain%5D', $wide);
        $this->assertStringNotContainsString('/folio/_blocks/hero/new', $wide, '"except": ["hero"]');

        // A hero block in the panel moves only to an area that takes it.
        $this->page('r2', 'other', 'wide', ['main' => [], 'hero' => [self::hero(self::HERO, 'Welcome')]]);
        $this->assertStringNotContainsString('name="_areas_move"', $this->html($this->get('/folio/page/r2/edit')), 'no area of "wide" takes it: Remove only');
    }

    #[RunInSeparateProcess]
    public function test_core_errors_show_beside_the_cards(): void
    {
        $this->removeScaffold();
        $this->scaffoldLayouts(); // without publishing: every save runs the rules
        $this->page('r1', 'about', 'landing', ['hero' => [], 'main' => []]);
        $form = $this->html($this->get('/folio/page/r1/edit'));
        $body = self::posted($form);
        $second = 'd4d4d4d4d4d4d4d4';
        $body['_areas']['hero'] = ['_order' => ['', self::HERO, $second], self::HERO => ['_block' => 'hero', 'headline' => 'One'], $second => ['_block' => 'hero', 'headline' => 'Two']];
        $body['_areas']['main'] = ['_order' => ['', self::OTHER], self::OTHER => ['_block' => 'hero', 'headline' => '']];

        $response = $this->post('/folio/page/r1', $body);

        $this->assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        $areas = $this->after($html, 'data-areas-section');
        $this->assertStringContainsString('<div class="form-error">The area &quot;hero&quot; takes at most 1 blocks.</div>', $this->between($areas, '<span class="form-label">Hero', 'name="_areas[main][_order][]"'), 'the area limit, beside the hero area');
        $card = $this->after($areas, 'id="block-' . self::OTHER . '"');
        $this->assertStringContainsString('Headline: This field is required.', substr($card, 0, 2000), 'the block\'s error on its card');
        $this->assertStringContainsString('<a href="#block-' . self::OTHER . '">This field is required.</a>', $this->between($areas, '<span class="form-label">Main', 'id="block-' . self::OTHER . '"'), 'and in the area\'s summary');
        $this->assertSame([], $this->stored('page', 'r1')->get('_areas')['hero'], 'nothing saved');
    }

    #[RunInSeparateProcess]
    public function test_refused_save_shows_the_areas_as_posted(): void
    {
        $this->page('r1', 'about', 'landing', ['hero' => [], 'main' => [self::prose(self::PROSE, ['en' => 'Stored'])]]);
        $body = self::posted($this->html($this->get('/folio/page/r1/edit')));
        $body['_areas']['main'][self::PROSE]['caption'] = 'Typed, not saved';
        $body['_areas']['footer'] = ['_order' => ['', self::OTHER], self::OTHER => ['_block' => 'prose', 'caption' => 'In an unknown area', 'body' => '']];

        $response = $this->post('/folio/page/r1', $body);

        $this->assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        $this->assertStringContainsString('value="Typed, not saved"', $html, 'the editor\'s edit stays in the form');
        $this->assertStringNotContainsString('value="Stored"', $html);
        $panel = $this->between($html, 'data-areas-panel', '</section>');
        $this->assertStringContainsString('value="In an unknown area"', $panel, 'the refused area, kept in the form');
        $this->assertStringContainsString('The layout &quot;landing&quot; has no area &quot;footer&quot;. Use one of: hero, main.', $panel, 'with its refusal');
        $this->assertSame('Stored', $this->stored('page', 'r1')->get('_areas')['main'][0]['caption']['en'], 'nothing saved');
    }

    #[RunInSeparateProcess]
    public function test_autosave_reports_a_refused_area_merge(): void
    {
        $this->page('r1', 'about', 'landing', ['hero' => [], 'main' => []]);
        $body = self::posted($this->html($this->get('/folio/page/r1/edit')));
        $body['title'] = 'Renamed';
        $body['_areas']['footer'] = ['_order' => ['', self::OTHER], self::OTHER => ['_block' => 'prose', 'caption' => 'x', 'body' => '']];

        $response = $this->post('/folio/page/r1/autosave', $body);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Not saved — Page areas: The layout &quot;landing&quot; has no area &quot;footer&quot;.', (string) $response->getBody());
        $this->assertSame('About', $this->stored('page', 'r1')->get('title')['en'], 'nothing saved, as for any refusal');
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function interfaceLanguages(): iterable
    {
        yield 'German' => ['de', ['>Layout</label>', 'Das Layout „gone“ ist nicht mehr verfügbar. Diese Seite erscheint als „Standard“, bis du ein Layout wählst.', 'In diesem Layout nicht angezeigt',
            'Diese Blöcke bleiben erhalten, aber die Seite zeigt sie nicht. Verschiebe sie in einen Bereich dieses Layouts oder entferne sie.', '<span>Verschieben</span>', 'Verschieben nach', 'Layout: Home (fest)', 'Standard (bis du ein Layout wählst)', 'Festgelegt durch „layouts.pinned“ in der Konfiguration des Typs.', 'Seitenbereiche']];
        yield 'French' => ['fr', ['>Mise en page</label>', 'La mise en page « gone » n&#039;est plus disponible. Cette page s&#039;affiche comme « Standard » jusqu&#039;à ce que vous choisissiez une mise en page.', 'Non affiché dans cette mise en page',
            'Ces blocs sont conservés, mais la page ne les affiche pas. Déplacez-les dans une zone de cette mise en page, ou retirez-les.', '<span>Déplacer</span>', 'Déplacer vers', 'Mise en page : Home (fixe)', 'Standard (jusqu&#039;à ce que vous choisissiez une mise en page)', 'Définie par « layouts.pinned » dans la configuration du type.', 'Zones de la page']];
    }

    /** @param list<string> $texts */
    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('interfaceLanguages')]
    public function test_the_editor_speaks_the_interface_language(string $locale, array $texts): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => '{$locale}'];\n");
        $this->page('r1', 'about', 'gone', ['main' => [], 'hero' => [self::hero(self::HERO, 'Welcome')]]);
        $this->page('r2', 'home', null, ['top' => []]);

        $html = $this->edit('r1') . $this->edit('r2');

        foreach ($texts as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['Not shown in this layout', 'These blocks are kept', 'is no longer available', '(fixed)', 'Move to', 'Set by '] as $english) {
            $this->assertStringNotContainsString($english, $html);
        }
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function edit(string $id): string
    {
        return $this->html($this->get('/folio/page/' . $id . '/edit'));
    }

    private function html(ResponseInterface $response): string
    {
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));

        return (string) $response->getBody();
    }

    /** The picker's switch: the form as posted, the layout chosen. */
    private function switchTo(string $form, string $layout, string $id): string
    {
        return $this->areas($form, $id, ['_areas_to' => $layout, '_layout' => $layout]);
    }

    /**
     * POST /folio/_areas as the picker or a Move button sends it (htmx: the whole record form, plus the button's value).
     *
     * @param array<string, string> $more
     */
    private function areas(string $form, string $id, array $more): string
    {
        $response = $this->post('/folio/_areas?' . http_build_query(['name' => '_areas', 'type' => 'page', 'id' => $id, 'locale' => 'en', 'depth' => '0']), $more + self::posted($form));
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));

        return $this->html($response);
    }

    private function between(string $html, string $from, string $to): string
    {
        $rest = $this->after($html, $from);

        return substr($rest, 0, (int) strpos($rest, $to));
    }

    private function after(string $html, string $from): string
    {
        $at = strpos($html, $from);
        $this->assertNotFalse($at, "\"{$from}\" not found");

        return substr($html, $at);
    }

    private function before(string $html, string $to): string
    {
        $at = strpos($html, $to);

        return $at === false ? $html : substr($html, 0, $at);
    }

    private static function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return $doc;
    }

    /** The page with the areas section replaced by the endpoint's answer, as htmx swaps it ($layout: the picker as the editor set it). */
    private static function swapped(string $page, string $answer, ?string $layout = null): string
    {
        $doc = self::dom($page);
        foreach ((new \DOMXPath($doc))->query('//select[@name="_layout"]/option') ?: [] as $option) {
            if ($layout !== null && $option instanceof \DOMElement) {
                $option->getAttribute('value') === $layout ? $option->setAttribute('selected', '') : $option->removeAttribute('selected');
            }
        }
        $new = (new \DOMXPath(self::dom($answer)))->query('//*[@data-areas-section]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $new);
        $old = (new \DOMXPath($doc))->query('//*[@id="' . $new->getAttribute('id') . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $old);
        $old->parentNode?->replaceChild($doc->importNode($new, true), $old);

        return (string) $doc->saveHTML();
    }

    /** The page without the element of this id (a removed card). */
    private static function without(string $page, string $id): string
    {
        $doc = self::dom($page);
        $el = (new \DOMXPath($doc))->query('//*[@id="' . $id . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $el);
        $el->parentNode?->removeChild($el);

        return (string) $doc->saveHTML();
    }

    /**
     * What a browser posts for the record form: its controls (and those naming it with form="…"),
     * skipping buttons, disabled controls and unchecked boxes.
     *
     * @return array<string, mixed>
     */
    private static function posted(string $html): array
    {
        $xpath = new \DOMXPath(self::dom($html));
        $controls = '(self::input or self::select or self::textarea)';
        $pairs = [];
        foreach ($xpath->query("//form[@id='folio-record']//*[{$controls} and (not(@form) or @form='folio-record')] | //*[{$controls} and @form='folio-record' and not(ancestor::form[@id='folio-record'])]") ?: [] as $el) {
            if (!$el instanceof \DOMElement || $el->getAttribute('name') === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $type = strtolower($el->getAttribute('type'));
            if ($el->tagName === 'input' && in_array($type, ['button', 'submit', 'reset', 'image', 'file'], true)) {
                continue;
            }
            if ($el->tagName === 'input' && in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) {
                continue;
            }
            $values = match ($el->tagName) {
                'textarea' => [(string) preg_replace('/^\r?\n/', '', $el->textContent)], // a browser drops the newline after <textarea>
                'select' => self::selected($el),
                default => [$el->getAttribute('value')],
            };
            foreach ($values as $value) {
                $pairs[] = rawurlencode($el->getAttribute('name')) . '=' . rawurlencode($value);
            }
        }
        parse_str(implode('&', $pairs), $body);

        return $body;
    }

    /** @return list<string> */
    private static function selected(\DOMElement $select): array
    {
        $options = $select->getElementsByTagName('option');
        $chosen = [];
        foreach ($options as $option) {
            if ($option->hasAttribute('selected')) {
                $chosen[] = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
            }
        }
        if ($chosen === [] && !$select->hasAttribute('multiple') && $options->length > 0) {
            $first = $options->item(0);
            $chosen[] = $first->hasAttribute('value') ? $first->getAttribute('value') : $first->textContent;
        }

        return $chosen;
    }
}
