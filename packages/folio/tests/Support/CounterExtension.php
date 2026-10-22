<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

/**
 * Writes a project extension "counter" (app/Folio/Counter, version 1.0.0) into a scaffolded
 * app: a "board" screen, and an install() that appends a line to installs.log per run.
 * Its classes are declared once per process, so tests using it run in separate processes.
 */
trait CounterExtension
{
    private ?\Closure $counterAutoload = null;

    /** @param list<string>|null $disabled part ids; null leaves extensions.json alone */
    private function counter(?array $disabled = []): void
    {
        $this->write('app/Folio/Counter/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Counter;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'counter', label: 'Counter', description: 'Counts things', version: '1.0.0')]
            final class Extension extends \Preflow\Folio\Extension\Extension
            {
                public function install(\Preflow\Core\Container\Container $c): void
                {
                    file_put_contents($c->get(\Preflow\Core\Application::class)->basePath('installs.log'), "install\n", FILE_APPEND);
                }
            }
            PHP);
        $this->write('app/Folio/Counter/Screens/Board.php', <<<'PHP'
            <?php
            namespace App\Folio\Counter\Screens;
            #[\Preflow\Folio\Extension\Attributes\FolioScreen('board', 'Board', permission: 'counter.view')]
            final class Board
            {
                #[\Preflow\Routing\Attributes\Get('/')]
                public function index(): string { return 'board'; }
            }
            PHP);
        if ($disabled !== null) {
            $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['counter' => ['disabled_parts' => $disabled]]]));
        }

        $root = $this->dir . '/app/Folio/';
        $this->counterAutoload ??= static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->counterAutoload);
    }

    private function removeCounter(): void
    {
        if ($this->counterAutoload !== null) {
            spl_autoload_unregister($this->counterAutoload);
        }
    }

    /** @return array<string, mixed> */
    private function extensionsFile(): array
    {
        return (array) json_decode((string) file_get_contents($this->dir . '/config/folio/extensions.json'), true);
    }
}
