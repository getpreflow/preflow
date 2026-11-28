<?php

declare(strict_types=1);

namespace Preflow\Blocks\Preview;

use Preflow\Components\Component;
use Preflow\Components\ComponentMap;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Container\Container;
use Preflow\View\AssetCollector;
use Preflow\View\SafeHtml;
use Preflow\View\TemplateEngineInterface;
use Psr\Container\ContainerInterface;

/**
 * A record rendered through one view of its type's preview, as a whole page:
 *
 * 1. the view's component (a name in the component map, or a class extending Component), made
 *    through the container, gets the props `record` (the record's values in the editing locale, as
 *    a page template reads them), `type` (the type key), `view` (the view key), `locale` and
 *    `preview` (true) — nothing else — and is rendered through ComponentRenderer::render(), so its
 *    error boundary shows when it fails;
 * 2. the canvas template (a name the template engine resolves; the site chooses it on purpose) gets
 *    `preview` (the component's HTML, printed as it is), `record`, `type`, `view` and `locale`;
 * 3. the CSS and JS collected while rendering go into the page (AssetCollector::injectInto()).
 *
 * It opens no render context: the caller renders inside the preview's (Folio: the live preview's
 * visitor context, which skips writes and captures what would be sent). An API controller can call
 * it the same way. A view key the preview lacks (or null) is its first view.
 *
 * @since 0.94.0
 */
final readonly class PreviewCanvas
{
    public function __construct(
        private ContainerInterface $container,
        private ComponentRenderer $renderer,
        private TemplateEngineInterface $engine,
        private ComponentMap $components,
        private ?AssetCollector $assets = null,
    ) {}

    /**
     * @param array<string, mixed> $record the record's values in $locale (what a page template reads)
     * @throws PreviewConfigException when the view's component is neither in the component map nor a component class
     */
    public function render(TypePreview $preview, ?string $view, array $record, string $locale, string $canvasTemplate): string
    {
        $chosen = $preview->view($view);
        $class = $this->classOf($preview->type, $chosen);

        $component = $this->container instanceof Container ? $this->container->make($class) : $this->container->get($class);
        $component->setProps([
            'record' => $record,
            'type' => $preview->type,
            'view' => $chosen->key,
            'locale' => $locale,
            'preview' => true,
        ]);
        $html = $this->renderer->render($component);

        $page = $this->engine->render($canvasTemplate, [
            'preview' => new SafeHtml($html),
            'record' => $record,
            'type' => $preview->type,
            'view' => $chosen->key,
            'locale' => $locale,
        ]);

        return $this->assets?->injectInto($page) ?? $page;
    }

    /**
     * @return class-string<Component>
     * @throws PreviewConfigException
     */
    private function classOf(string $type, PreviewView $view): string
    {
        $name = $view->component;
        $class = $this->components->classes[$name] ?? $name;
        if (class_exists($class) && is_subclass_of($class, Component::class)) {
            return $class;
        }

        throw new PreviewConfigException(sprintf(
            'Type "%s": preview view "%s" names the component "%s", which is neither in the component map nor a class extending %s. Name an existing component.',
            $type,
            $view->key,
            $name,
            Component::class,
        ));
    }
}
