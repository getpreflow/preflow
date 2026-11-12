<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Tests\Support\CounterExtension;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/** Extension::seed(): folio:extensions:apply runs it on every run, install() once per version. */
final class ExtensionsApplySeedTest extends TestCase
{
    use PublishingScaffold;
    use CounterExtension;

    protected function tearDown(): void
    {
        $this->removeCounter();
        if (isset($this->dir)) {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_seed_runs_on_every_apply_and_install_once(): void
    {
        $this->scaffoldPublishing();
        $this->counter();

        $this->assertStringContainsString('installed counter 1.0.0', $this->apply());
        // A seed with no lines to report prints nothing: a run with nothing to install still says so.
        $this->assertSame("nothing to do\n", $this->apply());

        $this->assertSame("install\n", (string) file_get_contents($this->dir . '/installs.log'));
        $this->assertSame("seed\nseed\n", (string) file_get_contents($this->dir . '/seeds.log'));
    }

    #[RunInSeparateProcess]
    public function test_seed_of_a_disabled_extension_does_not_run(): void
    {
        $this->scaffoldPublishing();
        $this->counter(null); // discovered, not enabled

        $this->assertSame("nothing to do\n", $this->apply());
        $this->assertFileDoesNotExist($this->dir . '/seeds.log');
    }

    #[RunInSeparateProcess]
    public function test_a_line_a_seed_returns_is_printed_and_counts_as_work(): void
    {
        $this->scaffoldPublishing();
        $this->counter();
        $this->apply(); // installs
        file_put_contents($this->dir . '/seed-report', '1');

        $this->assertSame("counter: seeded a thing\n", $this->apply());
    }

    #[RunInSeparateProcess]
    public function test_a_seed_declared_void_still_loads_and_applies(): void
    {
        $this->scaffoldPublishing();
        $this->counter(seedType: 'void');

        $this->assertStringContainsString('installed counter 1.0.0', $this->apply());
        $this->assertSame("nothing to do\n", $this->apply());
        $this->assertSame("seed\nseed\n", (string) file_get_contents($this->dir . '/seeds.log'));
    }

    private function apply(): string
    {
        ob_start();
        try {
            $this->assertSame(0, (new ExtensionsApplyCommand(fn () => $this->app()))->execute([]));
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }
}
