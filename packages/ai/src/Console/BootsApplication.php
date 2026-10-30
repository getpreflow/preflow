<?php

declare(strict_types=1);

namespace Preflow\Ai\Console;

use Preflow\Ai\Ai;
use Preflow\Core\Application;

/** The AI commands run against the app in the working directory (or a test factory). */
trait BootsApplication
{
    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null $stderr error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    private function error(string $text): void
    {
        fwrite($this->stderr ?? STDERR, $text);
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }

    /** The Ai service, or null (with a note) when the app doesn't list AiServiceProvider. */
    private function ai(Application $app): ?Ai
    {
        if (!$app->container()->bound(Ai::class)) {
            $this->error("preflow/ai is not set up: add Preflow\\Ai\\AiServiceProvider to config/providers.php.\n");

            return null;
        }

        return $app->container()->get(Ai::class);
    }
}
