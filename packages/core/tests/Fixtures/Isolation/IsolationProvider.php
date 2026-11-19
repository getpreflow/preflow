<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Isolation;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Http\RequestContext;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/** request_cookie(name): a cookie of the request being rendered, read as site code reads it. */
final class IsolationProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'request_cookie',
            callable: static function (string $name) use ($container): string {
                $request = $container->bound(RequestContext::class) ? $container->get(RequestContext::class)->request : null;
                $value = $request?->getCookieParams()[$name] ?? '';

                return is_string($value) ? $value : '';
            },
        ));
    }
}
