<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionsFile;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/extensions (Settings → Extensions): the discovered extensions, their parts and
 * installed versions. With config/folio.php extensions.allow_admin_changes (default:
 * app.debug) an extension or part toggles by POST, which writes config/folio/extensions.json;
 * install() is folio:extensions:apply's job, never the toggle's. Otherwise read-only.
 */
final class ExtensionsController
{
    public function __construct(
        private readonly ExtensionRegistry $extensions,
        private readonly ExtensionState $state,
        private readonly ExtensionsFile $file,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly string $prefix,
        private readonly bool $allowChanges,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/extensions.twig', [
            'prefix' => $this->prefix,
            'extensions' => $this->state->overview($this->extensions),
            'allow_changes' => $this->allowChanges,
            'csrf' => $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
        ]));
    }

    /** POST {prefix}/extensions/{name}: enabled=1|0, with part=<id> for one part. */
    public function toggle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->allowChanges) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        $name = (string) $request->getAttribute('name', '');
        $body = (array) $request->getParsedBody();
        $part = is_string($body['part'] ?? null) && $body['part'] !== '' ? $body['part'] : null;
        $definition = null;
        foreach ($this->extensions->all() as $candidate) {
            $definition = $candidate->name === $name ? $candidate : $definition;
        }
        if ($definition === null || ($part !== null && !$definition->hasPart($part))) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Unknown extension or part');
        }

        try {
            ($body['enabled'] ?? '') === '1' ? $this->file->enable($definition, $part) : $this->file->disable($definition, $part);
        } catch (\Preflow\Folio\Extension\ExtensionException $e) { // a part of an extension that is not enabled
            return new Response(422, ['Content-Type' => 'text/plain; charset=UTF-8'], $e->getMessage());
        }

        return new Response(302, ['Location' => $this->prefix . '/extensions']);
    }
}
