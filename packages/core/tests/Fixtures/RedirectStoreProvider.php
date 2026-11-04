<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\NotFoundLog;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectStore;

/** Binds an in-memory redirect store (/old, /legacy, /x) and a not-found log the way Folio would. */
final class RedirectStoreProvider extends ServiceProvider
{
    /** @var list<array{string, string}> */
    public static array $recorded = [];

    public function register(Container $container): void
    {
        $container->instance(RedirectStore::class, new class implements RedirectStore {
            public function exact(string $path, string $locale): ?Redirect
            {
                $targets = ['/old' => '/new', '/legacy' => '/elsewhere', '/x' => '/de/x'];

                return isset($targets[$path])
                    ? new Redirect(Redirect::id('exact', '', $path), $path, '', false, AreaMode::One, null, null, $targets[$path], 301)
                    : null;
            }

            public function areas(string $locale): array
            {
                return [];
            }

            public function hit(Redirect $redirect, \DateTimeImmutable $at): void {}
        });

        $container->instance(NotFoundLog::class, new class implements NotFoundLog {
            public function record(string $path, string $locale, \DateTimeImmutable $at): void
            {
                RedirectStoreProvider::$recorded[] = [$path, $locale];
            }
        });
    }
}
