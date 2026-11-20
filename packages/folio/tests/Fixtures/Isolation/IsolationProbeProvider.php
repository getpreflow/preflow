<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Isolation;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\RequestContext;
use Preflow\I18n\Translator;
use Preflow\View\Seo\SeoContext;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * The isolation fixture site's template functions, each reading as site code reads today:
 * - request_cookie(name): a cookie of the request bound in the container;
 * - seo_title(): the title in the SEO page meta;
 * - render_time(): "now" of the render's context (ATOM), '' outside a render.
 * Texts under 'isolation.' may be overridden, as a site marks its own.
 */
final class IsolationProbeProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $container->get(Translator::class)->overridable('isolation.');
        $engine = $container->get(TemplateEngineInterface::class);
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'request_cookie',
            callable: static function (string $name) use ($container): string {
                $value = $container->get(RequestContext::class)?->request?->getCookieParams()[$name] ?? '';

                return is_string($value) ? $value : '';
            },
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'seo_title',
            callable: static fn (): string => $container->bound(SeoContext::class) ? ($container->get(SeoContext::class)->get()?->title ?? '') : '',
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'render_time',
            callable: static fn (): string => Contexts::peek()?->now->format(DATE_ATOM) ?? '',
        ));
    }
}
