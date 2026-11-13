<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\View\FolioEngine;

/**
 * Settings → SEO audit's run partial (seo-audit/_progress.twig) in each state: which buttons it
 * offers (all plain POSTs to run, fresh=1 to start over), whether it polls the step route, the
 * notices, the generic failure line (never the step's message) and the command, named except
 * while the run steps.
 */
final class SeoAuditProgressTemplateTest extends TestCase
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

    /** @param array<string, mixed> $audit */
    private function render(array $audit): string
    {
        return $this->app()->container()->get(FolioEngine::class)->render('@folio/admin/seo-audit/_progress.twig', ['prefix' => '/folio', 'audit' => $audit + [
            'state' => 'idle', 'started' => false, 'complete' => false, 'done' => 0, 'total' => 0,
            'run_url' => '/folio/seo-audit/run', 'step_url' => '/folio/seo-audit/step', 'csrf' => 'tok',
        ]]);
    }

    /** @return list<array{label: string, fresh: bool}> the run forms' buttons */
    private static function buttons(string $html): array
    {
        preg_match_all('#<form method="post" action="/folio/seo-audit/run">(.*?)</form>#s', $html, $forms);
        $buttons = [];
        foreach ($forms[1] as $form) {
            self::assertStringContainsString('<input type="hidden" name="_csrf_token" value="tok">', $form);
            preg_match('#<button type="submit" class="btn btn-(?:primary|secondary)" data-busy-label="Auditing…">([^<]+)</button>#', $form, $b);
            self::assertNotEmpty($b, $form);
            $buttons[] = ['label' => $b[1], 'fresh' => str_contains($form, 'name="fresh" value="1"')];
        }

        return $buttons;
    }

    public function test_no_report_offers_run_and_the_command(): void
    {
        $html = $this->render([]);

        $this->assertSame([['label' => 'Run audit', 'fresh' => false]], self::buttons($html));
        $this->assertStringContainsString('No audit has run on this site yet.', $html);
        $this->assertStringContainsString('<code>preflow seo:audit</code>', $html);
        $this->assertStringNotContainsString('hx-post', $html);
        $this->assertStringNotContainsString('<progress', $html);
    }

    public function test_incomplete_offers_continue_and_start_over_with_the_progress(): void
    {
        $html = $this->render(['started' => true, 'done' => 3, 'total' => 10]);

        $this->assertSame([['label' => 'Continue', 'fresh' => false], ['label' => 'Start over', 'fresh' => true]], self::buttons($html));
        $this->assertStringContainsString('<progress max="10" value="3"></progress>', $html);
        $this->assertStringContainsString('3 of 10 pages audited', $html);
        $this->assertStringNotContainsString('hx-post', $html);
    }

    public function test_complete_offers_run_again_from_the_start(): void
    {
        $html = $this->render(['started' => true, 'complete' => true, 'done' => 10, 'total' => 10]);

        $this->assertSame([['label' => 'Run again', 'fresh' => true]], self::buttons($html));
        $this->assertStringNotContainsString('<progress', $html);
    }

    public function test_stepping_polls_the_step_route_with_a_continue_for_no_script_and_a_hidden_stalled_alert(): void
    {
        $html = $this->render(['state' => 'stepping', 'started' => true, 'done' => 1, 'total' => 4]);

        $this->assertMatchesRegularExpression('#<div id="folio-audit-progress"[^>]* data-folio-run-progress[^>]* hx-post="/folio/seo-audit/step" hx-trigger="load delay:300ms" hx-swap="outerHTML" hx-include="find \[name=_csrf_token\]">#', $html);
        $this->assertStringContainsString('in steps of about 20 seconds', $html);
        $this->assertMatchesRegularExpression('#<noscript>.*?Each Continue audits pages for about 20 seconds\..*?action="/folio/seo-audit/run".*?</noscript>#s', $html);
        $this->assertMatchesRegularExpression('#<div class="folio-alert folio-alert-error" role="alert" data-folio-run-stalled hidden>#', $html);
        // Continue twice (no script, stalled), never Start over while it runs.
        $this->assertSame([['label' => 'Continue', 'fresh' => false], ['label' => 'Continue', 'fresh' => false]], self::buttons($html));
    }

    public function test_running_elsewhere_warns_and_offers_try_again_without_polling(): void
    {
        $html = $this->render(['state' => 'elsewhere', 'started' => true, 'done' => 2, 'total' => 4]);

        $this->assertStringContainsString('<div class="folio-notice is-warning" role="status"><p>Another audit is running on this site', $html);
        $this->assertSame([['label' => 'Try again', 'fresh' => false]], self::buttons($html));
        $this->assertStringNotContainsString('hx-post', $html);
    }

    public function test_a_failed_step_shows_a_generic_line_with_continue(): void
    {
        // Even a message in the view (as before 0.74's fix) is never printed.
        $html = $this->render(['state' => 'failed', 'error' => 'Disk <full> at /srv/site', 'started' => true, 'done' => 2, 'total' => 4]);

        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert"><p>The last step failed. The details are in the server log.</p>', $html);
        $this->assertStringNotContainsString('Disk', $html);
        $this->assertStringNotContainsString('/srv/site', $html);
        $this->assertSame([['label' => 'Continue', 'fresh' => false], ['label' => 'Start over', 'fresh' => true]], self::buttons($html));
        $this->assertStringNotContainsString('hx-post', $html);
    }

    public function test_a_failed_first_step_offers_run(): void
    {
        $html = $this->render(['state' => 'failed']);

        $this->assertStringContainsString('The last step failed. The details are in the server log.', $html);
        $this->assertSame([['label' => 'Run audit', 'fresh' => false]], self::buttons($html));
    }

    public function test_the_command_is_named_in_every_state_but_stepping(): void
    {
        $line = 'On the server it runs with <code>preflow seo:audit</code>';
        foreach ([
            [],
            ['started' => true, 'done' => 3, 'total' => 10],
            ['started' => true, 'complete' => true, 'done' => 10, 'total' => 10],
            ['state' => 'elsewhere', 'started' => true, 'done' => 2, 'total' => 4],
            ['state' => 'failed', 'started' => true, 'done' => 2, 'total' => 4],
        ] as $audit) {
            $this->assertStringContainsString($line, $this->render($audit), json_encode($audit) ?: '');
        }
        $this->assertStringNotContainsString('preflow seo:audit', $this->render(['state' => 'stepping', 'started' => true, 'done' => 1, 'total' => 4]));
    }
}
