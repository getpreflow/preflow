<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\StarterApp;

/**
 * The README says: delete app/Components, app/pages and app/Controllers/Site and the API still
 * works. This proves it on a copy of the project without those three paths.
 */
final class ApiOnlyTest extends TestCase
{
    use StarterApp;

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_api_suite_passes_without_frontend(): void
    {
        $project = dirname(__DIR__);
        $output = [];
        $code = 0;
        // A new process, so its own PHPUnit run boots every test on an API-only copy.
        exec(
            'cd ' . escapeshellarg($project) . ' && STARTER_API_ONLY=1 ' . escapeshellarg(PHP_BINARY)
            . ' vendor/bin/phpunit --testsuite Api 2>&1',
            $output,
            $code,
        );
        $text = implode("\n", $output);

        $this->assertSame(0, $code, $text);
        $this->assertMatchesRegularExpression('/^OK \(/m', $text);
    }

    public function test_frontend_routes_are_gone_in_api_only_copy(): void
    {
        $this->bootStarter(apiOnly: true);

        $this->get('/jobs')->assertNotFound();
        $this->json('GET', '/api/jobs')->assertOk();
    }
}
