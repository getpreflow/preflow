<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * Builds the app's template engine when config/app.php names this class as its engine:
 *
 *     'engine' => App\View\PlatesEngineFactory::class,
 *
 * Core needs the engine while it boots (packages register their template functions, routing
 * scans app/pages for templateExtension()), before the service providers run. So it stands a
 * LazyTemplateEngine in for it: the factory is built through the container and create() is
 * called on the first render (or exists()), after the providers ran, and everything registered
 * until then is handed on. So the factory's constructor may take services a provider binds.
 * The engine gets the packages' template functions (component(), hd, csp_nonce(), form helpers,
 * t()…) and renders file pages and components.
 */
interface TemplateEngineFactory
{
    /**
     * The file extension of the engine's templates ('phtml', 'latte'…), without building it:
     * file pages are app/pages/*.{extension}, a component's template Name/Name.{extension}.
     */
    public static function templateExtension(): string;

    public function create(TemplateEngineContext $context): TemplateEngineInterface;
}
