<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Access\Access;
use Preflow\Core\Routing\Route;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Screen\ScreenRegistry;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every admin route asks one permission, by handler. "{type}" is the route's
 * type; "*.edit|create" means edit or create on any type (block editor
 * fragments have no type). Extension screens ask the permission their
 * #[FolioScreen] declares; SIGNED_IN asks none (the own account page, the language switch);
 * any other handler is refused.
 * Runs after AdminAuthMiddleware, so the user is known.
 */
final class FolioPermissionMiddleware implements MiddlewareInterface
{
    private const string NS = 'Preflow\\Folio\\Http\\';

    /** A rule that asks no permission: any signed-in user (their own account page). */
    public const string SIGNED_IN = '(signed in)';

    /** @var array<string, string> short handler => permission */
    private const array RULES = [
        'AdminController@index' => 'folio.dashboard',
        'AdminController@list' => '{type}.view',
        'AdminController@editForm' => '{type}.view',
        'AdminController@recordLabel' => '{type}.view',
        'AdminController@relationOptions' => '{type}.view',
        'AdminController@export' => '{type}.view',
        'AdminController@createForm' => '{type}.create',
        'AdminController@store' => '{type}.create',
        'AdminController@update' => '{type}.edit',
        'AdminController@reorder' => '{type}.edit',
        'AdminController@confirmTranslation' => '{type}.edit',
        'AiTranslateController@estimate' => '{type}.edit', // then ai.translate (in the controller)
        'AiTranslateController@suggest' => '{type}.edit',
        'AiTranslateController@runForm' => '{type}.edit',
        'AiTranslateController@plan' => '{type}.edit',
        'AiTranslateController@start' => '{type}.edit',
        'AiTranslateController@step' => '{type}.edit',
        'AiTranslateController@stop' => '{type}.edit',
        'AdminController@recordAction' => '{type}.edit', // then the action's own permission (in the controller)
        'AdminController@destroy' => '{type}.delete',
        'AdminController@deletePlan' => '{type}.delete',
        'AdminController@bulkDelete' => '{type}.delete',
        'AdminController@bulkAction' => '{type}.edit', // set-{field}; an extension's action then asks its own permission (in the controller)
        'PublishingController@autosave' => '{type}.edit',
        'PublishingController@discard' => '{type}.edit',
        'PublishingController@restore' => '{type}.edit',
        'PublishingController@share' => '{type}.edit',
        'PublishingController@revoke' => '{type}.edit',
        'PublishingController@unpublish' => '{type}.publish',
        'PreviewController@preview' => '{type}.edit',
        'AssetLibraryController@index' => 'assets.view',
        'AssetLibraryController@panel' => 'assets.view',
        'AssetLibraryController@upload' => 'assets.upload',
        'AssetLibraryController@save' => 'assets.edit',
        'AssetLibraryController@destroy' => 'assets.delete',
        'AssetLibraryController@replace' => 'assets.edit',
        'AssetLibraryController@editor' => 'assets.edit',
        'AssetLibraryController@edit' => 'assets.edit',
        'AssetLibraryController@makeCopy' => 'assets.edit',
        'AssetLibraryController@remake' => 'assets.edit',
        'AssetLibraryController@restoreVersion' => 'assets.edit',
        'AssetLibraryController@deleteVersion' => 'assets.delete',
        'AssetLibraryController@bin' => 'assets.delete',
        'AssetLibraryController@restoreFromBin' => 'assets.delete',
        'ExtensionsController@index' => 'folio.extensions',
        'ExtensionsController@toggle' => 'folio.extensions',
        'CacheController@index' => 'folio.cache',
        'CacheController@clear' => 'folio.cache',
        'CacheController@opcacheReset' => 'folio.cache',
        'TranslationsController@index' => 'translations.edit',
        'TranslationsController@save' => 'translations.edit',
        'TranslationsController@reset' => 'translations.edit',
        'BlockEditorController@newBlock' => '*.edit|create',
        'BlockEditorController@duplicate' => '*.edit|create',
        'AccountController@show' => self::SIGNED_IN,
        'AccountController@password' => self::SIGNED_IN,
        'LocaleController@update' => self::SIGNED_IN,
    ];

    public function __construct(
        private readonly Access $access,
        private readonly TypeCatalog $catalog,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ScreenRegistry $screens,
    ) {}

    /** The permission a handler requires; null for handlers that need none (or are unknown). */
    public static function rule(string $handler): ?string
    {
        return str_starts_with($handler, self::NS) ? (self::RULES[substr($handler, strlen(self::NS))] ?? null) : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(Route::class);
        $rule = $route instanceof Route ? self::rule($route->handler) : null;
        if ($rule === null && $route instanceof Route
            && ($permission = $this->screens->permissionFor(explode('@', $route->handler, 2)[0])) !== null) {
            // A screen's own permission, asked as is: never a {type} placeholder, wildcard or redirect.
            return $this->access->can($permission) ? $handler->handle($request) : $this->forbidden($request);
        }
        if ($rule === null) {
            return $this->forbidden($request); // fail closed: an unmapped admin route is never open
        }

        if ($rule === self::SIGNED_IN) {
            return $handler->handle($request);
        }

        // Block fragments belong to a form being filled in: creating counts as much as editing.
        if ($rule === '*.edit|create') {
            return $this->anyType('edit') || $this->anyType('create') ? $handler->handle($request) : $this->forbidden($request);
        }

        if (str_starts_with($rule, '{type}.')) {
            $type = (string) ($route->parameters['type'] ?? '');
            if (!$this->catalog->has($type)) {
                return $handler->handle($request); // the controller answers 404
            }
            $action = substr($rule, strlen('{type}.'));
            // Previewing a record that isn't saved yet is part of creating it.
            if ($route->handler === self::NS . 'PreviewController@preview' && !isset($route->parameters['id'])) {
                $action = 'create';
            }
            $rule = $type . '.' . $action;
        }

        if ($this->access->can($rule)) {
            return $handler->handle($request);
        }

        // The dashboard is where login lands: send people without it to what they may see.
        if ($rule === 'folio.dashboard' && ($first = $this->firstAllowedSection()) !== null) {
            return new Response(302, ['Location' => $first]);
        }

        return $this->forbidden($request);
    }

    private function anyType(string $action): bool
    {
        foreach ($this->catalog->all() as $listing) {
            if ($this->access->can($listing->key . '.' . $action)) {
                return true;
            }
        }

        return false;
    }

    private function firstAllowedSection(): ?string
    {
        foreach ($this->catalog->all() as $listing) {
            if ($this->access->can($listing->key . '.view')) {
                return $this->prefix . '/' . $listing->key;
            }
        }

        return $this->access->can('assets.view') ? $this->prefix . '/assets' : null;
    }

    private function forbidden(ServerRequestInterface $request): ResponseInterface
    {
        return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
    }
}
