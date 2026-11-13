<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Audit;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * globals_probe(): what a page that reads PHP's superglobals instead of the PSR request would see
 * ("cookies|post|remote address"), for SeoAuditRunTest.
 */
final class GlobalsProbeProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'globals_probe',
            callable: static fn (): string => implode(',', array_keys($_COOKIE)) . '|' . implode(',', array_keys($_POST)) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ));
    }
}
