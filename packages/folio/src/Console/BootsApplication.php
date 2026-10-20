<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;

/** Folio commands run against the app in the working directory (or a test factory). */
trait BootsApplication
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
