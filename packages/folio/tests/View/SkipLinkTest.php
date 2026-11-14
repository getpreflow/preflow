<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;

/**
 * The skip link (_layout.twig): the first thing a keyboard reaches on every admin page, before the
 * sidebar or the rail with their 20+ tab stops. It jumps to the main content, which can take focus.
 */
final class SkipLinkTest extends TestCase
{
    use StaffScaffold;

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

    private function dom(string $uri): \DOMXPath
    {
        $doc = new \DOMDocument();
        $this->assertTrue(@$doc->loadHTML($this->html($uri), LIBXML_NOERROR), 'the page parses');

        return new \DOMXPath($doc);
    }

    private function assertSkipLink(\DOMXPath $x, string $shell): void
    {
        // The first child of <body> is the skip link, so it is the first tab stop.
        $first = $x->query('/html/body/*[1]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $first, $shell);
        $this->assertSame('a', $first->nodeName, $shell . ': the first element of the body is a link');
        $this->assertSame('folio-skip', $first->getAttribute('class'), $shell);
        $this->assertSame('#folio-main', $first->getAttribute('href'), $shell);
        $this->assertSame('Skip to content', trim($first->textContent), $shell);

        // Nothing focusable comes before it.
        $focusable = $x->query('//body//*[self::a[@href] or self::button or self::input[not(@type="hidden")] or self::select or self::textarea or self::summary or (@tabindex and @tabindex != "-1")]');
        $this->assertGreaterThan(1, $focusable->length, $shell);
        $this->assertTrue($focusable->item(0)->isSameNode($first), $shell . ': the skip link is the first focusable element');

        // Its target is the one main content, which can take focus (but is not a tab stop).
        $main = $x->query('//*[@id="folio-main"]');
        $this->assertSame(1, $main->length, $shell . ': one target');
        $target = $main->item(0);
        $this->assertInstanceOf(\DOMElement::class, $target);
        $this->assertSame('main', $target->nodeName, $shell);
        $this->assertStringContainsString('folio-content', $target->getAttribute('class'), $shell);
        $this->assertSame('-1', $target->getAttribute('tabindex'), $shell);
    }

    #[RunInSeparateProcess]
    public function test_the_sidebar_shell_starts_with_the_skip_link(): void
    {
        $this->signIn();
        $x = $this->dom('/folio');
        $this->assertSame(0, $x->query('//*[contains(concat(" ", @class, " "), " folio-rail ")]')->length, 'the dashboard has the sidebar');
        $this->assertSkipLink($x, 'sidebar');
    }

    #[RunInSeparateProcess]
    public function test_the_rail_shell_starts_with_the_skip_link(): void
    {
        $this->signIn();
        $x = $this->dom('/folio/page/new');
        $this->assertSame(1, $x->query('//aside[contains(concat(" ", @class, " "), " folio-rail ")]')->length, 'an editor has the rail');
        $this->assertSkipLink($x, 'rail');
    }

    #[RunInSeparateProcess]
    public function test_the_skip_link_speaks_the_interface_language(): void
    {
        $this->removeSite();
        $this->staffSite(folio: ['locale' => 'de']);
        $this->signIn();
        $link = $this->dom('/folio')->query('/html/body/a[@class="folio-skip"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame('Zum Inhalt springen', trim($link->textContent));
    }
}
