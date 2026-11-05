<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\AriaHiddenFocusable;
use Preflow\View\Audit\Checks\DescriptionMissing;
use Preflow\View\Audit\Checks\HeadingCount;
use Preflow\View\Audit\Checks\ImageAltMissing;
use Preflow\View\Audit\Checks\ShareImageAltMissing;
use Preflow\View\Audit\Checks\TitleMissing;
use Preflow\View\Audit\Severity;

/** The page checks that read the page's own content: title, description, alt texts, headings, aria-hidden. */
final class ContentChecksTest extends TestCase
{
    use CheckFixtures;

    public function test_title_missing_passes_a_page_with_a_title(): void
    {
        $check = new TitleMissing($this->registry());

        $this->assertSame('title.missing', $check->id());
        $this->assertSame([], $check->check($this->page($this->html('<title>About us</title>'))));
    }

    public function test_title_missing_reports_no_title_or_an_empty_one_as_critical(): void
    {
        $check = new TitleMissing($this->registry());

        foreach (['', '<title>  </title>'] as $head) {
            $findings = $check->check($this->page($this->html($head, '<svg><title>An icon</title></svg>')));
            $this->assertSame(['title.missing|critical|https://site.test/about|en'], $this->summary($findings));
            $this->assertTargetsNamePages($findings);
        }
    }

    public function test_title_missing_names_the_locale_and_encoded_url_of_an_umlaut_page(): void
    {
        $check = new TitleMissing($this->registry());

        $this->assertSame([], $check->check($this->page($this->html('<title>Über uns – Spiele</title>'), 'https://site.test/de/%C3%BCber', 'de')));
        $findings = $check->check($this->page($this->html(''), 'https://site.test/fr/%C3%A0-propos', 'fr'));
        $this->assertSame(['title.missing|critical|https://site.test/fr/%C3%A0-propos|fr'], $this->summary($findings));
    }

    public function test_description_missing(): void
    {
        $check = new DescriptionMissing($this->registry());

        $this->assertSame('description.missing', $check->id());
        $this->assertSame([], $check->check($this->page($this->html('<title>A</title><meta name="Description" content="What it is.">'))));
        foreach (['', '<meta name="description" content=" ">'] as $head) {
            $findings = $check->check($this->page($this->html('<title>A</title>' . $head)));
            $this->assertSame(['description.missing|warning|https://site.test/about|en'], $this->summary($findings));
            $this->assertTargetsNamePages($findings);
        }
    }

    public function test_img_alt_missing_accepts_alt_text_and_an_empty_alt(): void
    {
        $check = new ImageAltMissing($this->registry());

        $this->assertSame('img.alt-missing', $check->id());
        $this->assertSame([], $check->check($this->page($this->html(body: '<h1>A</h1><img src="/a.jpg" alt="A cat"><img src="/line.svg" alt="">'))));
    }

    public function test_img_alt_missing_reports_once_per_page_with_a_count(): void
    {
        $check = new ImageAltMissing($this->registry());

        $findings = $check->check($this->page($this->html(body: '<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg" alt="C">')));

        $this->assertSame(['img.alt-missing|warning|https://site.test/about|en'], $this->summary($findings));
        $this->assertStringContainsString('2 images', $findings[0]->message);
        $this->assertStringContainsString('/a.jpg', $findings[0]->message);
        $this->assertTargetsNamePages($findings);
    }

    public function test_og_image_alt_missing(): void
    {
        $check = new ShareImageAltMissing($this->registry());

        $this->assertSame('og.image-alt-missing', $check->id());
        $this->assertSame([], $check->check($this->page($this->html('<title>A</title>'))), 'no share image, nothing to describe');
        $this->assertSame([], $check->check($this->page($this->html('<meta property="og:image" content="https://site.test/s.jpg"><meta property="og:image:alt" content="A cat">'))));

        $findings = $check->check($this->page($this->html('<meta property="og:image" content="https://site.test/s.jpg"><meta property="og:image:alt" content="">')));
        $this->assertSame(['og.image-alt-missing|notice|https://site.test/about|en'], $this->summary($findings));
        $this->assertTargetsNamePages($findings);
    }

    public function test_h1_count(): void
    {
        $check = new HeadingCount($this->registry());

        $this->assertSame('h1.count', $check->id());
        $this->assertSame([], $check->check($this->page($this->html(body: '<h1>One</h1><h2>Two</h2>'))));

        $none = $check->check($this->page($this->html(body: '<h2>Two</h2>')));
        $this->assertSame(['h1.count|notice|https://site.test/about|en'], $this->summary($none));
        $this->assertTargetsNamePages($none);

        $two = $check->check($this->page($this->html(body: '<h1>One</h1><h1>Two</h1>')));
        $this->assertSame(['h1.count|notice|https://site.test/about|en'], $this->summary($two));
        $this->assertStringContainsString('2', $two[0]->message);
    }

    public function test_aria_hidden_focusable_passes_hidden_content_nobody_can_focus(): void
    {
        $check = new AriaHiddenFocusable($this->registry());

        $this->assertSame('aria-hidden.focusable', $check->id());
        $this->assertSame([], $check->check($this->page($this->html(body: implode('', [
            '<span aria-hidden="true">★</span>',
            '<div aria-hidden="true"><a href="/x" tabindex="-1">x</a><button disabled>b</button><input type="hidden" name="h"><a>no href</a></div>',
            '<div aria-hidden="true" inert><a href="/y">y</a></div>',
            '<div aria-hidden="false"><a href="/z">z</a></div>',
            '<div aria-hidden="true"><div inert><a href="/i">inert below</a></div><a href="/h" hidden>hidden</a><p hidden><button>in hidden</button></p></div>',
            '<div aria-hidden="true"><fieldset disabled><input name="f"><button>b</button><select><option>o</option></select></fieldset></div>',
            '<div aria-hidden="true"><span tabindex="">empty</span><span tabindex="x">word</span></div>',
        ])))));
    }

    public function test_aria_hidden_focusable_reports_focusable_content_inside_or_on_hidden_elements(): void
    {
        $check = new AriaHiddenFocusable($this->registry());

        $findings = $check->check($this->page($this->html(body: implode('', [
            '<div aria-hidden="true"><a href="/x">x</a><div aria-hidden="true"><button>b</button></div></div>',
            '<span aria-hidden="TRUE" tabindex="0">s</span>',
        ]))));

        $this->assertSame(['aria-hidden.focusable|warning|https://site.test/about|en'], $this->summary($findings));
        $this->assertStringContainsString('3 ', $findings[0]->message);
        $this->assertTargetsNamePages($findings);
    }

    public function test_page_checks_say_what_to_do_in_one_sentence(): void
    {
        $registry = $this->registry();
        $page = $this->page('<html><head></head><body><img src="/a.jpg"><div aria-hidden="true"><a href="/">x</a></div></body></html>');
        $findings = [
            ...(new TitleMissing($registry))->check($page),
            ...(new DescriptionMissing($registry))->check($page),
            ...(new ImageAltMissing($registry))->check($page),
            ...(new HeadingCount($registry))->check($page),
            ...(new AriaHiddenFocusable($registry))->check($page),
        ];

        $this->assertCount(5, $findings);
        foreach ($findings as $finding) {
            $this->assertNotSame('', $finding->message);
            $this->assertMatchesRegularExpression('/\.$/', $finding->message);
            $this->assertContains($finding->severity, Severity::cases());
        }
    }
}
