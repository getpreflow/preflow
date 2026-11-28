<?php

declare(strict_types=1);

namespace Tests\Site;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Tests\Support\StarterApp;

/** The job type has no page of its own in Folio; its form shows the job as a card or as a list row (0.94). */
final class JobPreviewTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_job_form_offers_card_and_row_views(): void
    {
        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff('editor@example.com', 'demo-editor'))];

        $body = $this->get('/folio/job/' . md5('starter-job-2') . '/edit', $cookies)->assertOk()->body();

        $this->assertStringContainsString('data-preview-view="card"', $body);
        $this->assertStringContainsString('data-preview-view="row"', $body);
        $this->assertStringContainsString('List row', $body);
    }

    public function test_job_preview_renders_the_card_with_the_site_css(): void
    {
        $cookies = $this->signInStaff('editor@example.com', 'demo-editor');
        $header = $this->cookieHeader($cookies);
        $form = $this->get('/folio/job/' . md5('starter-job-2') . '/edit', ['Cookie' => $header])->assertOk()->body();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $form, $token);
        $company = $this->container()->get(\Preflow\Data\DataManager::class)->queryType('company')->get()->items()[0];

        $send = function (string $view) use ($cookies, $header, $token, $company): string {
            $request = (new Psr17Factory())->createServerRequest('POST', '/folio/job/' . md5('starter-job-2') . '/preview')
                ->withHeader('Cookie', $header)->withHeader('Sec-Fetch-Site', 'same-origin')
                ->withCookieParams($cookies)
                ->withParsedBody([
                    '_locale' => 'en', 'title' => 'Draft title for the card', 'company' => $company->getId(),
                    'place' => 'Bern', 'employmentType' => 'full_time', 'remote' => 'no',
                    '_preview_view' => $view, '_csrf_token' => html_entity_decode($token[1] ?? ''),
                ]);

            return (string) $this->app()->handle($request)->getBody();
        };

        $card = $send('card');
        $this->assertStringContainsString('Draft title for the card', $card);
        $this->assertStringContainsString((string) $company->get('name'), $card, 'the company\'s name');
        $this->assertStringContainsString('data-view="cards"', $card);
        $this->assertStringContainsString('--card:', $card, 'the site stylesheet (its colour variables) is on the canvas');
        $this->assertStringContainsString('font-family: system-ui', $card);
        $this->assertStringNotContainsString('<header class="site-header"', $card, 'the canvas is not the page layout');

        $this->assertStringContainsString('data-view="list"', $send('row'));
    }
}
