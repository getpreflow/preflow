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
        $log = tempnam(sys_get_temp_dir(), 'starter_api_only_');
        $output = [];
        $code = 0;
        // A new process, so its own PHPUnit run boots every test on an API-only copy. Each copy
        // it boots writes a line to the log, so the run proves it never saw the site paths.
        try {
            exec(
                'cd ' . escapeshellarg($project) . ' && STARTER_API_ONLY=1 STARTER_API_ONLY_LOG=' . escapeshellarg($log)
                . ' ' . escapeshellarg(PHP_BINARY) . ' vendor/bin/phpunit --testsuite Api 2>&1',
                $output,
                $code,
            );
            $boots = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        } finally {
            unlink($log);
        }
        $text = implode("\n", $output);

        $this->assertSame(0, $code, $text);
        // "OK (18 tests, ...)", or "OK, but there were issues!" and "Tests: 18, ..." while some
        // tests are marked incomplete; the exit code above already says nothing failed.
        $this->assertSame(1, preg_match('/^(?:OK \((\d+) tests?|Tests: (\d+),)/m', $text, $match), $text);
        $this->assertGreaterThanOrEqual(max((int) $match[1], (int) ($match[2] ?? 0)), count($boots), 'Every Api test boots at least one copy.');
        $this->assertSame(['site paths left: none'], array_values(array_unique($boots)));
    }

    public function test_frontend_routes_are_gone_in_api_only_copy(): void
    {
        $this->bootStarter(apiOnly: true);

        $this->get('/jobs')->assertNotFound();
        $this->json('GET', '/api/jobs')->assertOk();
    }
}
