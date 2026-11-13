<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The redesigned fields in the editor (0.75): a text value that starts with a line break keeps it in
 * the form column and in the side panel; the address field's affix; fields.js loads before
 * editor-frame.js.
 */
final class EditorFieldsTest extends TestCase
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

    /** The textareas of a page as the browser's parser reads them, by name. */
    private static function textareas(string $page): array
    {
        $doc = \Dom\HTMLDocument::createFromString($page, LIBXML_NOERROR, 'UTF-8');
        $out = [];
        foreach ($doc->querySelectorAll('textarea') as $textarea) {
            $out[(string) $textarea->getAttribute('name')] = [$textarea->textContent, $textarea->getAttribute('form'), $textarea->closest('aside') !== null];
        }

        return $out;
    }

    #[RunInSeparateProcess]
    public function test_a_text_starting_with_a_line_break_keeps_it_in_the_form_and_the_side_panel(): void
    {
        $this->writeModel('event', ['label' => 'Events', 'fields' => [
            'name' => ['type' => 'string'],
            'body' => ['type' => 'text'],
            'note' => ['type' => 'text', 'panel' => 'side'],
        ]]);
        $this->working('event', 'e1', ['name' => 'Fair', 'body' => "\nIndented start", 'note' => "\n\nTwo breaks"]);

        $areas = self::textareas((string) $this->get('/folio/event/e1/edit')->getBody());

        $this->assertSame(["\nIndented start", null, false], $areas['body'], 'the form column');
        $this->assertSame(["\n\nTwo breaks", 'folio-record', true], $areas['note'], 'the side panel (FormOwner)');
    }

    #[RunInSeparateProcess]
    public function test_the_address_field_has_the_prefix_and_the_copy_button(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key', 'url' => 'https://example.com'];\n");
        $this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo']]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();
        $this->assertMatchesRegularExpression('~<div class="folio-input-affix" data-folio-affix(?:="")?><span class="folio-input-prefix" aria-hidden="true">/</span><input type="text" name="slug" value="hello" id="slug"[^>]*form="folio-record">~', $page);
        $this->assertStringContainsString('data-address-before="https://example.com/" data-address-after=""', $page);

        $german = (string) $this->get('/folio/post/p1/edit?locale=de')->getBody();
        $this->assertStringContainsString('<span class="folio-input-prefix" aria-hidden="true">/de/</span>', $german);
        $this->assertStringContainsString('data-address-before="https://example.com/de/"', $german);
    }

    #[RunInSeparateProcess]
    public function test_fields_js_loads_before_the_editor_frame(): void
    {
        $this->working('note', 'n1', ['name' => 'A note']);
        $page = (string) $this->get('/folio/note/n1/edit')->getBody();
        $fields = strpos($page, '/_assets/fields.js?v=');
        $frame = strpos($page, '/_assets/editor-frame.js?v=');
        $this->assertNotFalse($fields, 'every editor: the date hint and the clear button of "Publish at…"');
        $this->assertLessThan($frame, $fields);
        $this->assertLessThan($fields, strpos($page, '/_assets/admin.js?v='));
        $this->assertSame(1, substr_count($page, '/_assets/admin.js?v='), 'once');
    }

    #[RunInSeparateProcess]
    public function test_the_drawer_editor_loads_admin_js_before_fields_js(): void
    {
        $this->working('note', 'n1', ['name' => 'A note']);
        foreach (['/folio/note/n1/edit?_drawer=1', '/folio/note/new?_drawer=1'] as $uri) {
            $page = (string) $this->get($uri)->getBody();
            $admin = strpos($page, '/_assets/admin.js?v=');
            $fields = strpos($page, '/_assets/fields.js?v=');
            $this->assertNotFalse($admin, $uri . ': folioT, copyLater and fillAssetField come from admin.js');
            $this->assertNotFalse($fields, $uri);
            $this->assertLessThan($fields, $admin, $uri);
            $this->assertLessThan(strpos($page, '/_assets/editor-frame.js?v='), $fields, $uri);
        }
    }
}
