<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\Pdf\PageSettings;
use Preflow\View\Pdf\PdfEngine;

/** Binds its own PDF engine, as a site that prefers another library would. */
final class PdfEngineProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(PdfEngine::class, static fn (): PdfEngine => new class implements PdfEngine {
            public function render(string $html, PageSettings $page, string $headerHtml, string $footerHtml): string
            {
                return 'custom:' . strip_tags($html);
            }
        });
    }
}
