<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeFamily;
use Preflow\Folio\Content\LocaleState;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\View\FolioEngine;
use Preflow\I18n\Completeness;

/**
 * The type tile and language indicator macros (partials/_type_icon.twig, _languages.twig), the
 * review card's tile, and the rule that only the macro names a family and only admin.css a colour.
 */
final class TypeIconTemplateTest extends TestCase
{
    use PublishingScaffold;

    private const string TEMPLATES = __DIR__ . '/../../templates';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @param array<string, mixed> $context */
    private function render(string $twig, array $context = []): string
    {
        $this->write('scratch/page.twig', $twig);
        $engine = $this->app()->container()->get(FolioEngine::class);
        $engine->addNamespace('scratch', $this->dir . '/scratch');

        return $engine->render('@scratch/page.twig', $context);
    }

    public function test_the_tile_names_the_family_and_shows_the_icon(): void
    {
        $twig = "{% import '@folio/admin/partials/_type_icon.twig' as types %}{{ types.type_icon(family, 'ui-file') }}";

        foreach (TypeFamily::cases() as $family) {
            $html = $this->render($twig, ['family' => $family]);

            $this->assertMatchesRegularExpression(
                '#^<span class="folio-type-icon" data-family="' . $family->value . '" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*icons\.svg[^"]*\#ui-file"></use></svg></span>$#',
                $html,
                $family->value,
            );
        }
    }

    public function test_no_family_is_the_neutral_tile_and_28_is_the_list_header_size(): void
    {
        $html = $this->render("{% import '@folio/admin/partials/_type_icon.twig' as types %}{{ types.type_icon(null, 'block') }}|{{ types.type_icon(family, 'ui-settings', 28) }}", ['family' => TypeFamily::Page]);

        [$neutral, $large] = explode('|', $html);
        $this->assertStringContainsString('<span class="folio-type-icon" data-family="" aria-hidden="true">', $neutral);
        $this->assertStringContainsString('#block"', $neutral);
        $this->assertStringContainsString('<span class="folio-type-icon folio-type-icon-lg" data-family="page" aria-hidden="true">', $large);
    }

    public function test_the_language_indicator_says_each_state(): void
    {
        $html = $this->render("{% import '@folio/admin/partials/_languages.twig' as langs %}{{ langs.languages(states, '/folio/post?unchecked=1') }}", ['states' => [
            ['code' => 'en', 'name' => 'English', 'state' => new LocaleState(Completeness::Complete, 4, 0, 0, 0)],
            ['code' => 'fr', 'name' => 'French', 'state' => new LocaleState(Completeness::Partial, 4, 3, 1, 0)],
            ['code' => 'de', 'name' => 'German', 'state' => new LocaleState(Completeness::Complete, 4, 0, 0, 2)],
            ['code' => 'it', 'name' => 'Italian', 'state' => new LocaleState(Completeness::Missing, 4, 0, 0, 0)],
        ]]);

        $this->assertStringStartsWith('<span class="folio-languages">', $html);
        $this->assertStringContainsString('<span class="folio-languages-item is-complete" title="English: complete"><span aria-hidden="true">EN</span><span class="folio-sr">English: complete</span></span>', $html);
        $this->assertStringContainsString('<span class="folio-languages-item is-partial is-stale" title="French: partial, 3 missing; fr · 1 outdated">', $html);
        $this->assertStringContainsString('<a class="folio-languages-item is-complete is-stale" title="German: complete; de · 2 not checked" href="/folio/post?unchecked=1">', $html, 'not checked links to the narrowed list');
        $this->assertStringContainsString('<span class="folio-languages-item is-missing" title="Italian: missing">', $html);
    }

    public function test_the_review_card_shows_the_types_tile(): void
    {
        $html = $this->app()->container()->get(FolioEngine::class)->render('@folio/admin/partials/dashboard-review.twig', ['prefix' => '/folio', 'review' => [
            'groups' => [['label' => 'Jobs', 'family' => TypeFamily::Reference, 'icon' => 'ui-file', 'rows' => [['title' => 'Dev', 'url' => '/folio/job/j1/edit', 'languages' => ['German (2)']]]]],
            'more' => 0, 'jobs' => [], 'no_worker' => false, 'polling' => false,
        ]]);

        $this->assertMatchesRegularExpression('#<h3 class="folio-review-type"><span class="folio-type-icon" data-family="reference" aria-hidden="true">.*?</span>Jobs</h3>#s', $html);
    }

    public function test_templates_name_no_type_token(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $checked++;
            $source = (string) file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen(self::TEMPLATES) + 1);
            $this->assertStringNotContainsString('--c-type-', $source, "{$name}: a family's colours live in admin.css");
            if ($name !== 'admin/partials/_type_icon.twig') {
                $this->assertDoesNotMatchRegularExpression('/data-family="[a-z]/', $source, "{$name}: a family comes from the data (the type_icon macro), never a literal");
                $this->assertStringNotContainsString('data-family', $source, "{$name}: only the type_icon macro writes data-family");
            }
        }
        $this->assertGreaterThan(50, $checked);
    }
}
