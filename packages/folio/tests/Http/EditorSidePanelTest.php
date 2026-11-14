<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The editor's side panel (0.75): Publishing, Address, Languages, Search result, Revisions, System
 * fields, extension and "panel": "side" cards, and the foot; each by what the type has. The main
 * column pairs "width": "half" fields in a row.
 */
final class EditorSidePanelTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private static function side(string $page): string
    {
        $start = (int) strpos($page, '<aside class="folio-side"');

        return substr($page, $start, (int) strpos($page, '</aside>', $start) - $start);
    }

    /** The record form, where the main column's fields are. */
    private static function form(string $page): string
    {
        $start = (int) strpos($page, '<form');

        return substr($page, $start, (int) strpos($page, '</form>', $start) - $start);
    }

    private function seoOn(): void
    {
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true];\n");
    }

    #[RunInSeparateProcess]
    public function test_sections_by_type_kind(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $this->working('note', 'n1', ['name' => 'A note']);

        $post = self::side((string) $this->get('/folio/post/p1/edit')->getBody());
        foreach (['id="folio-side-publishing"', 'id="folio-side-address"', 'id="folio-side-languages"', 'folio-side-system', 'folio-side-foot'] as $section) {
            $this->assertStringContainsString($section, $post);
        }
        $this->assertStringContainsString('Draft, not on the site', $post);
        $this->assertStringContainsString('aria-label="Delete “Hello”"', $post, 'the delete form, with the delete plan');
        $this->assertMatchesRegularExpression('#data-delete-plan="/folio/post/_delete-plan" data-delete-id="p1" data-removal-label="[^"]+" data-folio-discards#', $post, 'a delete saves no pending edits first; the removal dialog names the record');
        $this->assertStringNotContainsString('id="folio-side-search"', $post, 'no search result while SEO is off');

        // A publishing type without a frontend route or translations: no address, no languages.
        $note = self::side((string) $this->get('/folio/note/n1/edit')->getBody());
        $this->assertStringContainsString('id="folio-side-publishing"', $note);
        $this->assertStringNotContainsString('id="folio-side-address"', $note);
        $this->assertStringNotContainsString('id="folio-side-languages"', $note);

        // The singleton: no back button, no count, no delete.
        $site = (string) $this->get('/folio/site')->getBody();
        $this->assertStringNotContainsString('folio-back', $site);
        $this->assertStringNotContainsString('folio-side-delete', $site);
        $this->assertStringNotContainsString('folio-breadcrumb-count', $site);

        // The breadcrumb counts the type's records.
        $this->assertMatchesRegularExpression('~<a href="/folio/post">Posts</a><span class="folio-breadcrumb-count" title="1 entry">· 1</span>~', (string) $this->get('/folio/post/p1/edit')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_changes_list_the_fields_and_languages_that_differ_from_live(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello'], '_locales' => ['en']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Changed'], 'slug' => ['en' => 'hello'], '_locales' => ['en', 'de'], '_version' => 1]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();
        $side = self::side($page);

        $this->assertStringContainsString('<summary>2 changes</summary>', $side);
        $this->assertStringContainsString('<li>Title</li><li>Languages</li>', $side);
        $this->assertStringContainsString('Published, changes not live yet', $side);
        $this->assertStringContainsString('<span class="folio-badge is-published">Published</span> <span class="folio-badge is-changed">Changes not live</span>', $page);
        $this->assertStringContainsString('Discard changes', $side);
        $this->assertMatchesRegularExpression('~<form method="post" action="[^"]*" data-confirm="[^"]*" data-confirm-tone="danger" data-folio-discards>~', $side, 'a discard saves no pending edits first');
        $this->assertStringContainsString('>Unpublish</button>', $side);
    }

    #[RunInSeparateProcess]
    public function test_a_partial_language_shows_its_share_in_warning(): void
    {
        // German has the title and the slug, not the body: 2 of the 3 texts.
        $this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'],
            'body' => ['en' => 'Text'], '_locales' => ['en', 'de']]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();
        $side = self::side($page);

        $this->assertMatchesRegularExpression('~<li class="folio-language-row is-partial">.*?<rect class="folio-language-bar-fill" width="67" height="4">~s', $side);
        $this->assertMatchesRegularExpression('~<li class="folio-language-row is-complete is-active">.*?width="100"~s', $side);
        $this->assertStringContainsString('<span class="folio-language-row-state" aria-hidden="true">partial</span>', $side);
        $this->assertStringContainsString('<span class="folio-language-row-state" aria-hidden="true">editing</span>', $side);
        // The topbar's language switch: a warning ring for German, the words in its title.
        $this->assertMatchesRegularExpression('~<a class="is-partial" href="[^"]*locale=de" title="German: partial, 1 missing"~', $page);
        $this->assertMatchesRegularExpression('~<a class="is-complete" href="[^"]*locale=en" aria-current="true"~', $page);
    }

    #[RunInSeparateProcess]
    public function test_read_only_fields_move_to_system_fields(): void
    {
        $this->writeModel('event', ['label' => 'Events', 'fields' => [
            'name' => ['type' => 'string'],
            'imported' => ['type' => 'string', 'readonly' => true, 'label' => 'Imported from'],
            'closed' => ['type' => 'boolean', 'readonly' => true],
        ]]);
        $this->working('event', 'e1', ['name' => 'Fair', 'imported' => 'feed.xml']);

        $page = (string) $this->get('/folio/event/e1/edit')->getBody();
        $side = self::side($page);

        $this->assertMatchesRegularExpression('~<details class="folio-side-section folio-side-system">~', $side);
        $this->assertMatchesRegularExpression('~<dt>Imported from</dt><dd>feed.xml</dd>~', $side);
        $this->assertMatchesRegularExpression('~<dt>Closed</dt><dd>—</dd>~', $side, 'nothing stored: a dash');
        $this->assertMatchesRegularExpression('~<dt>ID</dt><dd class="folio-system-id"><span class="folio-mono" title="e1">e1</span><button type="button" class="btn btn-ghost btn-icon btn-sm" data-copy="e1"~', $side);
        $this->assertStringNotContainsString('folio-readonly', self::form($page), 'not in the main column');
        $this->assertStringNotContainsString('Imported from', self::form($page));
    }

    #[RunInSeparateProcess]
    public function test_a_side_field_renders_in_the_details_card_and_names_the_form(): void
    {
        $this->writeModel('event', ['label' => 'Events', 'fields' => [
            'name' => ['type' => 'string'],
            'position' => ['type' => 'number', 'panel' => 'side', 'help' => 'Lower numbers come first.'],
        ]]);
        $this->working('event', 'e1', ['name' => 'Fair', 'position' => 10]);

        $page = (string) $this->get('/folio/event/e1/edit')->getBody();
        $side = self::side($page);

        $this->assertStringContainsString('<h2 class="folio-panel-title" id="folio-side-details">Details</h2>', $side);
        $this->assertMatchesRegularExpression('~<input [^>]*name="position"[^>]* form="folio-record"~', $side);
        $this->assertStringNotContainsString('name="position"', self::form($page));

        // Posted with the form, it is saved.
        $this->assertSame(302, $this->post('/folio/event/e1', ['name' => 'Fair', 'position' => '20'])->getStatusCode());
        $this->assertSame(20, (int) $this->stored('event', 'e1')->get('position'));
    }

    #[RunInSeparateProcess]
    public function test_two_half_fields_share_a_row(): void
    {
        $this->writeModel('event', ['label' => 'Events', 'fields' => [
            'name' => ['type' => 'string', 'width' => 'half'],
            'place' => ['type' => 'string', 'width' => 'half'],
            'note' => ['type' => 'text'],
            'from' => ['type' => 'string', 'width' => 'half'],
        ]]);
        $this->working('event', 'e1', ['name' => 'Fair']);

        $form = self::form((string) $this->get('/folio/event/e1/edit')->getBody());

        $this->assertMatchesRegularExpression('~<div class="folio-field-row">\s*<div class="folio-field-wrap">.*?name="name".*?<div class="folio-field-wrap">.*?name="place".*?</div>\s*</div>\s*</div>~s', $form);
        $this->assertSame(2, substr_count($form, 'class="folio-field-row"'), 'name + place, then from on its own row');
        $this->assertDoesNotMatchRegularExpression('~folio-field-row">\s*<div class="folio-field-wrap">\s*<div class="form-group[^"]*">\s*<label[^>]*>Note~', $form, 'a full field never joins a row');
    }

    public function test_an_unknown_panel_or_width_is_a_boot_error(): void
    {
        foreach ([['panel', 'left', '"panel" must be "main" or "side"'], ['width', 'third', '"width" must be "full" or "half"'], ['width', true, '"width" must be "full" or "half"']] as [$option, $value, $message]) {
            $dir = sys_get_temp_dir() . '/folio_layout_' . bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['name' => ['type' => 'string', $option => $value]]]));
            try {
                (new TypeCatalog($dir))->all();
                $this->fail("{$option}: {$value} was accepted");
            } catch (FrontendConfigException $e) {
                $this->assertSame('Type "thing": field "name": ' . $message, $e->getMessage());
            } finally {
                unlink($dir . '/thing.json');
                rmdir($dir);
            }
        }

        $dir = sys_get_temp_dir() . '/folio_layout_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['name' => ['type' => 'string', 'panel' => 'side', 'width' => 'half']]]));
        $this->assertCount(1, (new TypeCatalog($dir))->all(), 'the two values each key allows');
        unlink($dir . '/thing.json');
        rmdir($dir);
    }

    #[RunInSeparateProcess]
    public function test_search_result_falls_back_to_the_warning(): void
    {
        $this->seoOn();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $side = self::side((string) $this->get('/folio/post/p1/edit')->getBody());

        $this->assertStringContainsString('id="folio-side-search"', $side);
        $this->assertStringContainsString('<p class="folio-search-address folio-mono">hello</p>', $side, 'no app.url: the path alone');
        $this->assertStringContainsString('<p class="folio-search-title" data-folio-search-title>Hello</p>', $side);
        $this->assertStringContainsString('<a href="#folio-tab-seo" data-folio-tab-link="seo">No description yet — add one in SEO & sharing.</a>', $side);

        // A share description fills it.
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], '_seo' => ['description' => ['en' => 'All about hello.']]]);
        $side = self::side((string) $this->get('/folio/post/p1/edit')->getBody());
        $this->assertStringContainsString('<p class="folio-search-description" data-folio-search-description>All about hello.</p>', $side);
        $this->assertStringNotContainsString('No description yet', $side);
    }
}
