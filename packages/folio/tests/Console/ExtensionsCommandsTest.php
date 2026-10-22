<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Console\ExportCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Console\ExtensionsCheckCommand;
use Preflow\Folio\Console\ExtensionsDisableCommand;
use Preflow\Folio\Console\ExtensionsEnableCommand;
use Preflow\Folio\Console\ExtensionsListCommand;
use Preflow\Folio\Console\FolioCommands;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Tests\Support\CounterExtension;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\TarReader;

final class ExtensionsCommandsTest extends TestCase
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

    public function test_folio_lists_the_extension_commands(): void
    {
        foreach ([ExtensionsListCommand::class, ExtensionsEnableCommand::class, ExtensionsDisableCommand::class, ExtensionsApplyCommand::class, ExtensionsCheckCommand::class] as $class) {
            $this->assertContains($class, FolioCommands::all());
        }
    }

    #[RunInSeparateProcess]
    public function test_apply_installs_once_per_version(): void
    {
        $this->scaffoldPublishing();
        $this->counter();

        [$code, $out] = $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));
        $this->assertSame(0, $code);
        $this->assertStringContainsString('installed counter 1.0.0', $out);
        $this->assertSame('1.0.0', $this->app()->container()->get(ExtensionState::class)->installedVersion('counter'));

        [$code, $out] = $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));
        $this->assertSame(0, $code);
        $this->assertSame("nothing to do\n", $out);
        $this->assertSame("install\n", (string) file_get_contents($this->dir . '/installs.log'));

        // Another installed version (an older release on this server): installs again.
        $this->app()->container()->get(ExtensionState::class)->markInstalled('counter', '0.9.0');
        [, $out] = $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));
        $this->assertStringContainsString('installed counter 1.0.0 (was 0.9.0)', $out);
        $this->assertSame("install\ninstall\n", (string) file_get_contents($this->dir . '/installs.log'));
    }

    #[RunInSeparateProcess]
    public function test_apply_skips_disabled_extensions(): void
    {
        $this->scaffoldPublishing();
        $this->counter(null); // discovered, not enabled

        [$code, $out] = $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertSame("nothing to do\n", $out);
        $this->assertFileDoesNotExist($this->dir . '/installs.log');
    }

    #[RunInSeparateProcess]
    public function test_apply_runs_table_sync(): void
    {
        $this->scaffoldPublishing('sqlite');
        // Default storage SQL: the install state needs its table too.
        $this->write('config/data.php', <<<'PHP'
            <?php
            return [
                'drivers' => [
                    'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],
                    'sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite'],
                ],
                'default' => 'sqlite',
                'models_path' => __DIR__ . '/models',
            ];
            PHP);
        $this->counter();

        [$code, $out] = $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertStringContainsString("created table post\n", $out);
        $this->assertStringContainsString("created table folio_extension_state\n", $out);
        $this->assertStringContainsString('installed counter 1.0.0', $out);
        $this->assertSame('1.0.0', $this->app()->container()->get(ExtensionState::class)->installedVersion('counter'));
        $this->assertSame("nothing to do\n", $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()))[1]);
    }

    #[RunInSeparateProcess]
    public function test_enable_disable_write_file(): void
    {
        $this->scaffoldPublishing();
        $this->counter(null);
        $file = $this->dir . '/config/folio/extensions.json';

        $this->assertSame(0, $this->runCommand(new ExtensionsEnableCommand(fn () => $this->app()), ['counter'])[0]);
        $this->assertSame("{\n    \"enabled\": {\n        \"counter\": {\n            \"disabled_parts\": []\n        }\n    }\n}\n", file_get_contents($file));

        $this->assertSame(0, $this->runCommand(new ExtensionsDisableCommand(fn () => $this->app()), ['counter', '--part=screen:board'])[0]);
        $this->assertSame(['enabled' => ['counter' => ['disabled_parts' => ['screen:board']]]], $this->extensionsFile());

        $this->assertSame(0, $this->runCommand(new ExtensionsEnableCommand(fn () => $this->app()), ['counter', '--part=screen:board'])[0]);
        $this->assertSame(['enabled' => ['counter' => ['disabled_parts' => []]]], $this->extensionsFile());

        $this->assertSame(0, $this->runCommand(new ExtensionsDisableCommand(fn () => $this->app()), ['counter'])[0]);
        $this->assertSame(['enabled' => []], $this->extensionsFile());

        [$code, $out] = $this->runCommand(new ExtensionsEnableCommand(fn () => $this->app()), ['ghost']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown extension "ghost"', $out);
        [$code, $out] = $this->runCommand(new ExtensionsEnableCommand(fn () => $this->app()), ['counter', '--part=screen:nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('"screen:nope"', $out);
        $this->assertSame(['enabled' => []], $this->extensionsFile());
    }

    #[RunInSeparateProcess]
    public function test_check_reports_error_exit_1(): void
    {
        $this->scaffoldPublishing();
        $this->counter();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");

        [$code, $out] = $this->runCommand(new ExtensionsCheckCommand($this->dir));
        $this->assertSame(0, $code, $out);
        $this->assertSame("ok\n", $out);

        // Without debug an unknown extension in the file is logged and ignored; check forces debug, so it throws.
        $this->write('config/folio/extensions.json', '{"enabled": {"counter": {"disabled_parts": []}, "ghost": {"disabled_parts": []}}}');
        [$code, $out] = $this->runCommand(new ExtensionsCheckCommand($this->dir));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('enables unknown extension "ghost"', $out);

        // A screen colliding with a type (ScreenRegistry, at boot).
        $this->write('config/folio/extensions.json', '{"enabled": {"counter": {"disabled_parts": []}}}');
        $this->write('app/Folio/Counter/Screens/Clash.php', <<<'PHP'
            <?php
            namespace App\Folio\Counter\Screens;
            #[\Preflow\Folio\Extension\Attributes\FolioScreen('post', 'Clash', permission: 'counter.clash')]
            final class Clash
            {
                #[\Preflow\Routing\Attributes\Get('/')]
                public function index(): string { return 'clash'; }
            }
            PHP);
        [$code, $out] = $this->runCommand(new ExtensionsCheckCommand($this->dir));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('"post"', $out);
    }

    #[RunInSeparateProcess]
    public function test_list_shows_parts(): void
    {
        $this->scaffoldPublishing();
        $this->counter(['screen:board']);

        [$code, $out] = $this->runCommand(new ExtensionsListCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertSame("counter 1.0.0  enabled  installed: none\n  screen:board  Board  off\n", $out);

        $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));
        $this->counter([]);
        $this->assertSame("counter 1.0.0  enabled  installed: 1.0.0\n  screen:board  Board  on\n", $this->runCommand(new ExtensionsListCommand(fn () => $this->app()))[1]);
    }

    #[RunInSeparateProcess]
    public function test_extension_state_not_exported(): void
    {
        $this->scaffoldPublishing();
        $this->counter();
        $this->runCommand(new ExtensionsApplyCommand(fn () => $this->app()));
        $this->assertSame('1.0.0', $this->app()->container()->get(ExtensionState::class)->installedVersion('counter'));

        $this->assertArrayNotHasKey(ExtensionState::TABLE, ContentSet::fromApp($this->app())->tables());
        $out = fopen('php://temp', 'w+b');
        $err = fopen('php://temp', 'w+b');
        $this->assertSame(0, (new ExportCommand(fn () => $this->app(), $out, $err))->execute(['--stdout']));
        rewind($out);
        $names = array_map(static fn (array $e) => $e[0], iterator_to_array((new TarReader($out))->entries(), false));
        $this->assertContains('folio-export.json', $names);
        $this->assertSame([], array_filter($names, static fn (string $n) => str_contains($n, 'extension_state')));
    }

    /** @return array{int, string} */
    private function runCommand(CommandInterface $command, array $args = []): array
    {
        ob_start();
        try {
            $code = $command->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }
}
