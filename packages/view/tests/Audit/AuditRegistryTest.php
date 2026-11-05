<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Audit\Target;

final class AuditRegistryTest extends TestCase
{
    public function test_returns_registered_checks_in_order(): void
    {
        $registry = new AuditRegistry();
        $a = $this->pageCheck('a');
        $b = $this->pageCheck('b');
        $s1 = $this->siteCheck('s1');
        $s2 = $this->siteCheck('s2');
        $r1 = $this->runCheck('r1');
        $r2 = $this->runCheck('r2');

        $registry->addPage($a);
        $registry->addSite($s1);
        $registry->addRun($r1);
        $registry->addPage($b);
        $registry->addRun($r2);
        $registry->addSite($s2);

        $this->assertSame([$a, $b], $registry->pageChecks());
        $this->assertSame([$s1, $s2], $registry->siteChecks());
        $this->assertSame([$r1, $r2], $registry->runChecks());
    }

    public function test_built_in_checks_are_added_on_first_use_and_come_first(): void
    {
        $calls = 0;
        $builtIn = $this->pageCheck('built-in');
        $builtInRun = $this->runCheck('built-in-run');
        $registry = new AuditRegistry(function (AuditRegistry $registry) use (&$calls, $builtIn, $builtInRun): bool {
            $calls++;
            $registry->addPage($builtIn);
            $registry->addRun($builtInRun);

            return true;
        });
        $own = $this->pageCheck('own');
        $ownSite = $this->siteCheck('own-site');

        $registry->addPage($own);
        $registry->addSite($ownSite);
        $this->assertSame(0, $calls, 'adding checks builds no built-in ones');

        $this->assertSame([$builtIn, $own], $registry->pageChecks());
        $this->assertSame([$ownSite], $registry->siteChecks());
        $this->assertSame([$builtInRun], $registry->runChecks());
        $this->assertSame([$builtIn, $own], $registry->pageChecks());
        $this->assertSame(1, $calls);
    }

    public function test_built_in_checks_not_ready_yet_are_asked_for_again(): void
    {
        $ready = false;
        $builtIn = $this->pageCheck('built-in');
        $registry = new AuditRegistry(function (AuditRegistry $registry) use (&$ready, $builtIn): bool {
            if (!$ready) {
                return false;
            }
            $registry->addPage($builtIn);

            return true;
        });
        $own = $this->pageCheck('own');
        $registry->addPage($own);

        $this->assertSame([$own], $registry->pageChecks(), 'read before the built-ins can be made');
        $ready = true;
        $this->assertSame([$builtIn, $own], $registry->pageChecks());
        $this->assertSame([$builtIn, $own], $registry->pageChecks());
    }

    public function test_starts_empty_and_resolves_no_target_without_a_resolver(): void
    {
        $registry = new AuditRegistry();

        $this->assertSame([], $registry->pageChecks());
        $this->assertSame([], $registry->siteChecks());
        $this->assertSame([], $registry->runChecks());
        $this->assertNull($registry->target('https://site.test/about', 'en'));
    }

    public function test_target_asks_the_resolver_with_url_and_locale(): void
    {
        $registry = new AuditRegistry();
        $seen = [];
        $registry->setTargets(static function (string $url, string $locale) use (&$seen): ?Target {
            $seen[] = [$url, $locale];

            return $url === 'https://site.test/de/spiele' ? new Target('record', 'game', '7') : null;
        });

        $this->assertEquals(new Target('record', 'game', '7'), $registry->target('https://site.test/de/spiele', 'de'));
        $this->assertNull($registry->target('https://site.test/other', 'en'));
        $this->assertSame([['https://site.test/de/spiele', 'de'], ['https://site.test/other', 'en']], $seen);
    }

    private function pageCheck(string $id): PageCheck
    {
        return new class ($id) implements PageCheck {
            public function __construct(private readonly string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function check(RenderedPage $page): array
            {
                return [];
            }
        };
    }

    private function siteCheck(string $id): SiteCheck
    {
        return new class ($id) implements SiteCheck {
            public function __construct(private readonly string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function check(PageRenderer $renderer): array
            {
                return [];
            }
        };
    }

    private function runCheck(string $id): RunCheck
    {
        return new class ($id) implements RunCheck {
            public function __construct(private readonly string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function collect(RenderedPage $page): void {}

            public function finish(): array
            {
                return [];
            }

            public function state(): array
            {
                return [];
            }

            public function restore(array $state): void {}
        };
    }
}
