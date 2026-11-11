<?php

declare(strict_types=1);

namespace Preflow\View;

use Preflow\Core\Error\ErrorRendererInterface;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An app's own error pages: app/pages/_errors/<status>.<ext> (404.twig, 500.twig, …), else
 * _errors/error.<ext> for any status, rendered through the app's engine like a file page, so they
 * extend the site's layout. Without such a template, or when it fails to render, the built-in page
 * answers: never a blank one. The application uses it on a live site and for internal renders (the
 * SEO audit); development keeps the detailed page, and API requests keep JSON (ErrorHandler).
 *
 * Template variables: `status` (404), `title` ("Not Found"), `message` (what a visitor may read:
 * the status text for 403/404/405 and for any exception that is no HttpException), and `request`
 * and `route` as file pages have them.
 *
 * @since 0.66
 */
final class ErrorPageRenderer implements ErrorRendererInterface
{
    /** The folder in app/pages; its leading "_" keeps it out of the routes. */
    public const string DIR = '_errors';

    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly AssetCollector $assets,
        private readonly ErrorRendererInterface $fallback,
        private readonly ?HypermediaProtocols $hypermedia = null,
        /** @var (\Closure(string): void)|null told why an error page failed (the app's logger); default the PHP log */
        private readonly ?\Closure $report = null,
    ) {}

    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        $template = $this->template($statusCode);
        if ($template === null) {
            return $this->fallback->render($exception, $request, $statusCode);
        }

        try {
            // The failed page may have printed the head already, and may have left a component's CSS
            // scope set: the error page starts with nothing printed and no scope.
            return $this->assets->reprinting(fn (): string => $this->assets->injectInto($this->engine->render($template, [
                'status' => $statusCode,
                'title' => ProdErrorRenderer::title($exception, $statusCode),
                'message' => ProdErrorRenderer::message($exception, $statusCode),
                'request' => PageResponder::requestVariable($request, $this->hypermedia),
                'route' => (object) [],
            ])));
        } catch (\Throwable $e) {
            ($this->report ?? error_log(...))('The error page ' . $template . ' failed to render, the built-in page answered: ' . $e::class . ': ' . $e->getMessage());

            return $this->fallback->render($exception, $request, $statusCode);
        }
    }

    /** The template for the status, else the catch-all, else null. */
    private function template(int $statusCode): ?string
    {
        $extension = $this->engine->getTemplateExtension();
        foreach ([(string) $statusCode, 'error'] as $name) {
            $template = self::DIR . '/' . $name . '.' . $extension;
            try {
                if ($this->engine->exists($template)) {
                    return $template;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
