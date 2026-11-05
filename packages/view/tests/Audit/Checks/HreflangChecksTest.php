<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\HreflangUnpaired;
use Preflow\View\Audit\Checks\HreflangXDefaultMissing;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

/** The language-version checks: alternates that pair up, and an x-default on multilingual sites. */
final class HreflangChecksTest extends TestCase
{
    use CheckFixtures;

    /** @var array<string, int> calls to the public pages' source, by locale */
    private array $asked = [];

    public function test_x_default_missing_passes_a_page_with_x_default_and_monolingual_sites(): void
    {
        $check = new HreflangXDefaultMissing($this->registry(), multilingual: true);

        $this->assertSame('hreflang.x-default-missing', $check->id());
        $this->assertSame([], $check->check($this->page($this->html($this->alternates(['en' => '/about', 'de' => '/de/%C3%BCber', 'x-default' => '/about'])))));
        $this->assertSame([], $check->check($this->page($this->html())), 'a page in one language only has no alternates to default');

        $single = new HreflangXDefaultMissing($this->registry(), multilingual: false);
        $this->assertSame([], $single->check($this->page($this->html($this->alternates(['en' => '/about'])))));
    }

    public function test_x_default_missing_reports_alternates_without_x_default(): void
    {
        $check = new HreflangXDefaultMissing($this->registry(), multilingual: true);

        $findings = $check->check($this->page($this->html($this->alternates(['en' => '/about', 'de' => '/de/%C3%BCber'])), 'https://site.test/de/%C3%BCber', 'de'));

        $this->assertSame(['hreflang.x-default-missing|notice|https://site.test/de/%C3%BCber|de'], $this->summary($findings));
        $this->assertTargetsNamePages($findings);
    }

    public function test_unpaired_passes_language_versions_that_link_each_other(): void
    {
        $check = new HreflangUnpaired($this->registry(), $this->publicPages(), ['en', 'de', 'fr']);
        $check->restore([]);
        $links = ['en' => 'https://site.test/about', 'de' => 'https://site.test/de/%C3%BCber', 'fr' => '/fr/%C3%A0-propos', 'x-default' => '/about'];

        $this->assertSame('hreflang.unpaired', $check->id());
        $check->collect($this->page($this->html($this->alternates($links)), 'https://site.test/about', 'en'));
        // The German page names its own URL decoded; it is the same page.
        $check->collect($this->page($this->html($this->alternates([...$links, 'de' => 'https://site.test/de/über'])), 'https://site.test/de/%C3%BCber', 'de'));
        $check->collect($this->page($this->html($this->alternates($links)), 'https://site.test/fr/%C3%A0-propos', 'fr'));

        $this->assertSame([], $check->finish());
    }

    public function test_unpaired_reports_an_alternate_that_isnt_audited_or_doesnt_link_back(): void
    {
        $check = new HreflangUnpaired($this->registry(), $this->publicPages(), ['en', 'de', 'fr']);
        $check->restore([]);

        // en names de and fr; de links back, fr doesn't; it isn't audited at all.
        $check->collect($this->page($this->html($this->alternates(['en' => '/about', 'de' => '/de/%C3%BCber', 'fr' => '/fr/%C3%A0-propos'])), 'https://site.test/about', 'en'));
        $state = json_decode((string) json_encode($check->state()), true);
        $resumed = new HreflangUnpaired($this->registry(), $this->publicPages(), ['en', 'de', 'fr']);
        $resumed->restore($state);
        $resumed->collect($this->page($this->html($this->alternates(['en' => '/about', 'de' => '/de/%C3%BCber'])), 'https://site.test/de/%C3%BCber', 'de'));
        $resumed->collect($this->page($this->html($this->alternates(['fr' => '/fr/%C3%A0-propos'])), 'https://site.test/fr/%C3%A0-propos', 'fr'));

        $findings = $resumed->finish();

        $this->assertSame(['hreflang.unpaired|warning|https://site.test/about|en'], $this->summary($findings));
        $this->assertStringContainsString('https://site.test/fr/%C3%A0-propos', $findings[0]->message);
        $this->assertStringContainsString('link back', $findings[0]->message);
        $this->assertTargetsNamePages($findings);

        $missing = new HreflangUnpaired($this->registry(), $this->publicPages(), ['en', 'de', 'fr']);
        $missing->restore([]);
        $missing->collect($this->page($this->html($this->alternates(['en' => '/about', 'it' => '/it/chi-siamo'])), 'https://site.test/about', 'en'));
        $notAudited = $missing->finish();
        $this->assertSame(['hreflang.unpaired|warning|https://site.test/about|en'], $this->summary($notAudited));
        $this->assertStringContainsString('https://site.test/it/chi-siamo', $notAudited[0]->message);
        $this->assertStringContainsString('public page', $notAudited[0]->message);
    }

    public function test_unpaired_in_a_partial_run_passes_public_pages_that_werent_audited(): void
    {
        // seo:audit --url /fr/…: only the French page is collected; its language versions are public pages.
        $check = new HreflangUnpaired($this->registry(), $this->publicPages(), ['en', 'de', 'fr']);
        $check->restore([]);
        $check->collect($this->page(
            $this->html($this->alternates(['en' => '/about', 'de' => '/de/%C3%BCber', 'fr' => '/fr/%C3%A0-propos', 'x-default' => '/about'])),
            'https://site.test/fr/%C3%A0-propos',
            'fr',
        ));
        $this->assertSame([], $this->asked, 'collecting reads no public pages');

        $this->assertSame([], $check->finish());
        $this->assertSame(['en' => 1, 'de' => 1, 'fr' => 1], $this->asked, 'finish() reads each locale once');
    }

    private function publicPages(): PublicPages
    {
        $pages = new PublicPages();
        $pages->source(function (string $locale): array {
            $this->asked[$locale] = ($this->asked[$locale] ?? 0) + 1;

            return match ($locale) {
                'en' => [new PublicPage('About', 'https://site.test/about')],
                'de' => [new PublicPage('Über uns', '/de/über')],
                'fr' => [new PublicPage('À propos', '/fr/%C3%A0-propos')],
                default => [],
            };
        });

        return $pages;
    }

    /** @param array<string, string> $links hreflang => href */
    private function alternates(array $links): string
    {
        $html = '<title>T</title>';
        foreach ($links as $lang => $href) {
            $html .= '<link rel="alternate" hreflang="' . $lang . '" href="' . htmlspecialchars($href) . '">';
        }

        return $html;
    }
}
