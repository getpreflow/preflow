<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a draft (in-progress, unsaved) record through the real frontend
 * template for live preview. Side-effect-free: never writes storage, never
 * persists uploads, never calls saveType (so no validation), and ignores the
 * published-status gate. Enabled only for the frontend type.
 */
final class PreviewController
{
    private readonly DraftRecordFactory $drafts;

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly RecordRenderer $records,
        private readonly TemplateEngineInterface $engine,
        private readonly FrontendResolver $frontend,
        ?DraftRecordFactory $drafts = null,
        private readonly ?ContentLocale $contentLocale = null,
        private readonly ?\Preflow\View\AssetCollector $assets = null,
        private readonly ?AdminAssetUrls $assetUrls = null,
    ) {
        $this->drafts = $drafts ?? new DraftRecordFactory($fieldTypes);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $route = $this->frontend->routeFor($type);
        if (!$this->catalog->has($type) || $route === null) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $this->registry->get($type);
        $id = (string) $request->getAttribute('id', '');
        $existing = $id !== '' ? ($this->dm->findType($typeDef->key, $id)?->toArray() ?? []) : [];
        $requested = ((array) $request->getParsedBody())['_locale'] ?? null;
        $locale = is_string($requested) && $typeDef->locales->has($requested) ? $requested : $typeDef->locales->default;
        $draft = $this->drafts->build($typeDef, $request, $existing + [$typeDef->idField => $id], $locale, writeUploads: false);
        $this->contentLocale?->set($locale);

        $html = $this->engine->render(FrontendController::template($this->engine, $route), [
            'record' => $draft->localized($locale)->toArray(),
            'rendered' => $this->records->renderedMap($draft, $locale),
            'type' => $type,
            'preview' => true,
            'locale' => $locale,
            'alternates' => [],
        ]);

        $html = $this->assets?->injectInto($html) ?? $html;

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->withEditor($html));
    }

    /**
     * The preview-only editor bridge (hover outlines, click-to-edit). External
     * same-origin files, so the page's own CSP allows them without a nonce.
     */
    private function withEditor(string $html): string
    {
        if ($this->assetUrls === null) {
            return $html;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $css = '<link rel="stylesheet" href="' . $e(($this->assetUrls)('preview-editor.css')) . '">' . "\n";
        $js = '<script src="' . $e(($this->assetUrls)('preview-editor.js')) . '" defer></script>' . "\n";

        $html = ($pos = stripos($html, '</head>')) !== false ? substr_replace($html, $css, $pos, 0) : $css . $html;

        return ($pos = strripos($html, '</body>')) !== false ? substr_replace($html, $js, $pos, 0) : $html . $js;
    }

}
