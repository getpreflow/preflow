<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;

/**
 * The styled dialog (partials/_dialog.twig, admin.js's folioConfirm / folioAlert): every layout renders
 * it once; Folio's scripts and templates never ask through the browser's own confirm, alert or prompt;
 * "Save and leave" (the unsaved-changes dialog) saves with _folio_next and the save goes there.
 */
final class DialogTest extends TestCase
{
    use StaffScaffold;

    private const FOLIO = __DIR__ . '/../..';

    protected function setUp(): void
    {
        $this->staffSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    private function signIn(): void
    {
        $this->makeStaff();
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    private function dialogs(string $html): \DOMNodeList
    {
        $doc = new \DOMDocument();
        $this->assertTrue(@$doc->loadHTML($html, LIBXML_NOERROR), 'the page parses');
        $nodes = (new \DOMXPath($doc))->query('//dialog[@data-folio-dialog]');
        $this->assertNotFalse($nodes);

        return $nodes;
    }

    #[RunInSeparateProcess]
    public function test_each_layout_renders_the_dialog_once(): void
    {
        // The auth layout (forgot password), before signing in.
        $this->assertCount(1, $this->dialogs($this->html('/folio/forgot')), '_auth_layout.twig');

        $this->signIn();
        $page = $this->html('/folio');
        $dialogs = $this->dialogs($page);
        $this->assertCount(1, $dialogs, '_layout.twig');
        $dialog = $dialogs->item(0);
        $this->assertInstanceOf(\DOMElement::class, $dialog);
        $this->assertSame('folio-dialog-title', $dialog->getAttribute('aria-labelledby'));
        $this->assertSame('folio-dialog-message', $dialog->getAttribute('aria-describedby'));
        $this->assertStringContainsString('data-folio-dialog-cancel>Cancel</button>', $page, 'labels in the interface language');
        $this->assertStringContainsString('data-folio-dialog-confirm>Confirm</button>', $page);
        $this->assertStringContainsString('data-text-ok="OK"', $page);

        $this->assertCount(1, $this->dialogs($this->html('/folio/page/new?_drawer=1')), '_drawer_layout.twig');
        $this->assertCount(1, $this->dialogs($this->html('/folio/page/new')), 'an editor (_layout.twig with the rail)');
    }

    public function test_folio_never_asks_through_the_browsers_own_boxes(): void
    {
        $files = glob(self::FOLIO . '/assets/*.js') ?: [];
        $this->assertNotEmpty($files);
        foreach (['/templates', '/src'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::FOLIO . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'twig') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $found = [];
        foreach ($files as $file) {
            // confirm( / alert( / prompt( called as a global or on window (not folioConfirm(, removalPrompt(, x.confirm().
            if (preg_match_all('/(?<![\w$.:])(?:window\.)?(?:confirm|alert|prompt)\(/', (string) file_get_contents($file), $m) > 0) {
                $found[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
            // htmx's own question is a window.confirm too: hx-confirm goes through htmx:confirm, which asks the dialog.
        }
        $this->assertSame([], $found, 'Folio asks through window.folioConfirm / folioAlert');
        $this->assertGreaterThan(5, count($files), 'the scan saw the scripts and the templates');
    }

    public function test_the_scan_would_catch_the_browsers_boxes(): void
    {
        $pattern = '/(?<![\w$.:])(?:window\.)?(?:confirm|alert|prompt)\(/';
        foreach (['window.confirm("x")', 'if (!confirm(a))', 'onclick="return confirm(\'x\')"', 'window.alert(t)', 'prompt(q)'] as $bad) {
            $this->assertSame(1, preg_match($pattern, $bad), $bad);
        }
        foreach (['folioConfirm({})', 'removalPrompt(lines)', 'this.confirm(x)', 'folioAlert({})', 'data-confirm="x"', 'htmx:confirm (onHtmxConfirm)', 'a leave prompt (an'] as $fine) {
            $this->assertSame(0, preg_match($pattern, $fine), $fine);
        }
    }

    #[RunInSeparateProcess]
    public function test_save_and_leave_goes_to_folio_next_once_saved(): void
    {
        $this->signIn();

        // A new record saved through "Save and leave": the server goes to the link instead of the list.
        $created = $this->post('/folio/page', ['title' => 'About', '_folio_next' => '/folio/staff?q=ada']);
        $this->assertSame(302, $created->getStatusCode());
        $this->assertSame('/folio/staff?q=ada', $created->getHeaderLine('Location'));
        $page = $this->dm()->queryType('page')->get()->first();
        $this->assertNotNull($page, 'saved');

        // An edit too.
        $id = (string) $page->getId();
        $updated = $this->post('/folio/page/' . $id, ['title' => 'About us', '_folio_next' => '/folio']);
        $this->assertSame('/folio', $updated->getHeaderLine('Location'));

        // Only a path on this site: anything else is ignored (the usual list).
        foreach (['//evil.test/x', 'https://evil.test/', '/\\evil.test', '/folio/../x', 'javascript:alert(1)'] as $next) {
            $this->assertSame('/folio/page', $this->post('/folio/page/' . $id, ['title' => 'About us', '_folio_next' => $next])->getHeaderLine('Location'), $next);
        }
    }
}
