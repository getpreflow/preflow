<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Core\Routing\Route;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeActions;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses a route that would do what its type does not allow (data's "readonly": true,
 * "creatable": false), with Folio's 403 page saying why. The route's record verb is the one its
 * permission names (FolioPermissionMiddleware::rule(): "{type}.create", "{type}.edit", …), so a
 * new route is covered by the mapping it needs anyway. On every admin route, with or without
 * sign-in: the type's rule holds for everyone, a role cannot lift it.
 *
 * @since 0.78
 */
final class TypeActionsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TypeActions $actions,
        private readonly TypeCatalog $catalog,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ?DisplayLabels $labels = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(Route::class);
        $rule = $route instanceof Route ? FolioPermissionMiddleware::rule($route->handler) : null;
        if ($rule === null || !str_starts_with($rule, '{type}.')) {
            return $handler->handle($request);
        }
        $type = (string) ($route->parameters['type'] ?? '');
        if (!$this->catalog->has($type)) {
            return $handler->handle($request); // the controller answers 404
        }
        $verb = substr($rule, strlen('{type}.'));
        // Previewing a record that isn't saved yet is part of creating it (as the permission check has it).
        if ($route->handler === 'Preflow\\Folio\\Http\\PreviewController@preview' && !isset($route->parameters['id'])) {
            $verb = 'create';
        }
        if ($this->actions->allowsVerb($type, $verb)) {
            return $handler->handle($request);
        }

        $label = $this->labels?->type($type) ?? $this->catalog->get($type)?->label ?? ucfirst($type);

        return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog, self::reason($this->actions, $type, $label), [
            'label' => FolioText::get('folio.form.back_to', ['label' => $label]),
            'url' => $this->prefix . '/' . $type,
        ]);
    }

    /** Why the type refuses: read-only, or not created here (folio.errors.readonly / not_creatable). */
    public static function reason(TypeActions $actions, string $type, string $label): string
    {
        return FolioText::get($actions->readonly($type) ? 'folio.errors.readonly' : 'folio.errors.not_creatable', ['label' => $label]);
    }
}
