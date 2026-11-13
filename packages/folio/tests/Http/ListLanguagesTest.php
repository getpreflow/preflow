<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\MachineMarks;

/**
 * The list's language column: the language indicator (partials/_languages.twig) from
 * TranslationStatus::state() for every language of the type, in its order. Complete, partial
 * (some texts missing) and missing (the record is not visible in the language) are classes; a
 * language with outdated or not checked translations is stale, and not checked ones keep their link
 * to the list narrowed to them. A "post" with title, slug and body in en/de.
 */
final class ListLanguagesTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function app(): Application
    {
        if ($this->booted === null) {
            $this->booted = Application::create($this->dir);
            $this->booted->boot();
        }

        return $this->booted;
    }

    private function row(string $html, string $id): string
    {
        $at = strpos($html, 'href="/folio/post/' . $id . '/edit"');
        $this->assertNotFalse($at, "row {$id}");
        $end = strpos($html, '</tr>', $at);

        return substr($html, $at, $end - $at);
    }

    private function list(): string
    {
        return (string) $this->get('/folio/post')->getBody();
    }

    public function test_complete_partial_and_missing_classes(): void
    {
        $this->working('post', 'full', ['title' => ['en' => 'About', 'de' => 'Über'], 'slug' => ['en' => 'about', 'de' => 'ueber'], 'body' => ['en' => 'b', 'de' => 'k'], '_locales' => ['en', 'de']]);
        $this->working('post', 'half', ['title' => ['en' => 'Team', 'de' => 'Team'], 'slug' => ['en' => 'team'], 'body' => ['en' => 'b'], '_locales' => ['en', 'de']]);
        $this->working('post', 'none', ['title' => ['en' => 'News'], 'slug' => ['en' => 'news'], 'body' => ['en' => 'b'], '_locales' => ['en']]);

        $html = $this->list();

        $this->assertStringContainsString('<span class="folio-languages-item is-complete" title="German: complete"><span aria-hidden="true">DE</span>', $this->row($html, 'full'));
        $this->assertStringContainsString('<span class="folio-languages-item is-partial" title="German: partial, 2 missing"><span aria-hidden="true">DE</span>', $this->row($html, 'half'));
        // Not visible in German: missing, however many texts it has.
        $this->assertStringContainsString('<span class="folio-languages-item is-missing" title="German: missing"><span aria-hidden="true">DE</span>', $this->row($html, 'none'));
        // Every language of the type is shown, the default first.
        foreach (['full', 'half', 'none'] as $id) {
            $this->assertMatchesRegularExpression('#<span class="folio-languages-item is-complete" title="English: complete"><span aria-hidden="true">EN</span>.*?>DE</span>#s', $this->row($html, $id));
        }
        $this->assertStringNotContainsString('folio-badge is-incomplete', $html, 'the old "de · 2 missing" pills are gone');
    }

    public function test_stale_locale_keeps_unchecked_link(): void
    {
        $this->working('post', 'mt', [
            'title' => ['en' => 'About', 'de' => 'Über'], 'slug' => ['en' => 'about', 'de' => 'ueber'], 'body' => ['en' => 'b', 'de' => 'k'],
            '_locales' => ['en', 'de'],
            '_machine_translated' => ['de' => ['title' => MachineMarks::fingerprint('Über')]],
        ]);

        $row = $this->row($this->list(), 'mt');

        $this->assertStringContainsString('<a class="folio-languages-item is-complete is-stale" title="German: complete; de · 1 not checked" href="/folio/post?unchecked=1"><span aria-hidden="true">DE</span><span class="folio-sr">German: complete; de · 1 not checked</span></a>', $row);
    }

    public function test_outdated_translations_are_stale_without_a_link(): void
    {
        // The German title was made from an English text that has changed since (its fingerprint differs).
        $this->working('post', 'old', [
            'title' => ['en' => 'About us', 'de' => 'Über'], 'slug' => ['en' => 'about', 'de' => 'ueber'], 'body' => ['en' => 'b', 'de' => 'k'],
            '_locales' => ['en', 'de'],
            \Preflow\Folio\Content\TranslationStatus::KEY => ['de' => ['title' => 'the fingerprint of an older text']],
        ]);

        $row = $this->row($this->list(), 'old');

        $this->assertStringContainsString('<span class="folio-languages-item is-complete is-stale" title="German: complete; de · 1 outdated">', $row);
    }
}
