<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Target;

/** Pages and a registry whose target names the page, for the built-in checks' tests. */
trait CheckFixtures
{
    private function registry(): AuditRegistry
    {
        $registry = new AuditRegistry();
        $registry->setTargets(static fn (string $url, string $locale): Target => new Target('record', 'page', "{$locale}|{$url}"));

        return $registry;
    }

    /** @param array<string, list<string>> $headers */
    private function page(string $html, string $url = 'https://site.test/about', string $locale = 'en', array $headers = []): RenderedPage
    {
        return new RenderedPage($url, $locale, 200, ['Content-Type' => ['text/html; charset=UTF-8'], ...$headers], $html);
    }

    /** A document with $head inside <head> and $body inside <body>. */
    private function html(string $head = '<title>About</title>', string $body = '<h1>About</h1>'): string
    {
        return "<!doctype html><html><head><meta charset=\"utf-8\">{$head}</head><body>{$body}</body></html>";
    }

    /**
     * @param list<Finding> $findings
     * @return list<string> "check|severity|url|locale" per finding
     */
    private function summary(array $findings): array
    {
        return array_map(static fn (Finding $f): string => "{$f->check}|{$f->severity->value}|{$f->url}|{$f->locale}", $findings);
    }

    /** @param list<Finding> $findings */
    private function assertTargetsNamePages(array $findings): void
    {
        foreach ($findings as $finding) {
            $this->assertEquals(new Target('record', 'page', "{$finding->locale}|{$finding->url}"), $finding->target);
        }
    }
}
