<?php

declare(strict_types=1);

namespace Preflow\Blocks\Preview;

use Preflow\Components\Component;
use Preflow\Components\ComponentMap;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\RefusedInPreview;
use Preflow\Core\Exceptions\HttpException;
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
 *    error boundary shows when it fails; a component that cannot be built (or whose setProps()
 *    throws) shows the same boundary output, inside the canvas (an HttpException and
 *    RefusedInPreview are rethrown, as ComponentRenderer does). A container other than Preflow's
 *    Container is asked with get(), and must build a fresh instance for each call;
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

        $props = [
            'record' => $record,
            'type' => $preview->type,
            'view' => $chosen->key,
            'locale' => $locale,
            'preview' => true,
        ];
        $html = $this->componentHtml($class, $props);

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
     * The component built through the container and rendered through ComponentRenderer::render();
     * the boundary's output when it cannot be built.
     *
     * @param class-string<Component> $class
     * @param array<string, mixed> $props
     */
    private function componentHtml(string $class, array $props): string
    {
        try {
            $component = $this->container instanceof Container ? $this->container->make($class) : $this->container->get($class);
            $component->setProps($props);
        } catch (HttpException|RefusedInPreview $e) {
            // What ComponentRenderer::render() rethrows: the response's status (a 403, a 404…).
            throw $e;
        } catch (\Throwable $e) {
            return $this->buildFailure($class, $props, $e);
        }

        return $this->renderer->render($component);
    }

    /**
     * What the error boundary shows for a component that could not be built (an unresolvable
     * constructor dependency, a constructor or setProps() that throws), phase "construct": the same
     * output as a failure while rendering, inside the canvas. The boundary describes a stand-in made
     * without its constructor (its class and props); if that one cannot serve, a plain stand-in.
     *
     * @param class-string<Component> $class
     * @param array<string, mixed> $props
     */
    private function buildFailure(string $class, array $props, \Throwable $e): string
    {
        try {
            $standIn = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $standIn->setProps($props);

            return $this->renderer->renderError($standIn, $e, 'construct');
        } catch (\Throwable) {
            $standIn = new class extends Component {};
            $standIn->setProps($props);

            return $this->renderer->renderError($standIn, $e, 'construct');
        }
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
