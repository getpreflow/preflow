<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;

/**
 * Boots the app with app.debug forced on, so every extension error that production only
 * logs (unknown names in the file, screens, customizations, permissions, settings) throws.
 * The config value is set after config/app.php is read and before boot; nothing else changes.
 */
final class ExtensionsCheckCommand implements CommandInterface
{
    /** @param string|null $basePath the app root (default: the working directory) */
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    public function getName(): string { return 'folio:extensions:check'; }
    public function getDescription(): string { return 'Check Folio extensions as debug does (exit 1 on any error)'; }

    public function execute(array $args): int
    {
        try {
            $app = Application::create($this->basePath ?? (getcwd() ?: '.'));
            if ((int) $app->config()->get('app.debug', 0) === 0) {
                $app->config()->set('app.debug', 1);
            }
            $app->boot();
        } catch (\Throwable $e) {
            echo $e->getMessage() . "\n";

            return 1;
        }
        echo "ok\n";

        return 0;
    }
}
