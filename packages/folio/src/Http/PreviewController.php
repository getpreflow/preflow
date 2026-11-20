<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
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
 * published-status gate. Enabled only for the frontend type. It renders within a context
 * of its own (since 0.81.0): an anonymous visitor on the public surface in the editing
 * locale, reading working copies, purpose Preview, writes skipped and what it would send
 * captured. The whole page (layout, navigation) is PagePreviewController's.
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
        /** not used since 0.81.0 (the preview renders in a context of its own); kept for the constructor's shape */
        private readonly ?ContentLocale $contentLocale = null,
        private readonly ?\Preflow\View\AssetCollector $assets = null,
        private readonly ?AdminAssetUrls $assetUrls = null,
        /** not used since 0.81.0 (the translator follows the render context); kept for the constructor's shape */
        private readonly ?\Preflow\I18n\Translator $translator = null,
    ) {
        $this->drafts = $drafts ?? new DraftRecordFactory($fieldTypes);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $route = $this->frontend->routeFor($type);
        if (!$this->catalog->has($type) || $route === null) {
            return NotFound::response($request, \Preflow\Folio\FolioText::get('folio.errors.not_found'));
        }

        $typeDef = $this->registry->get($type);
        $id = (string) $request->getAttribute('id', '');
        $existing = $id !== '' ? ($this->dm->findType($typeDef->key, $id)?->toArray() ?? []) : [];
        $requested = ((array) $request->getParsedBody())['_locale'] ?? null;
        $locale = is_string($requested) && $typeDef->locales->has($requested) ? $requested : $typeDef->locales->default;
        $draft = $this->drafts->build($typeDef, $request, $existing + [$typeDef->idField => $id], $locale, writeUploads: false);

        // The frontend's template as a visitor sees the page: nobody signed in (a member-only
        // block stays out although the editor is signed in), the visitor's texts in the editing
        // locale, working copies of referenced records. Nothing it renders leaves a trace
        // (Purpose::Preview): the data writes it would make are skipped and what it would send
        // (mail, cookies) is captured, never sent with the admin's request (since 0.81.0). Only
        // for this stretch: the admin's own locale, identity and texts are back afterwards.
        $preview = Contexts::current()->asVisitor()->public()->in($locale)->workingCopies()
            ->capturing()->skippingWrites()->because(Purpose::Preview);
        $html = Contexts::within($preview, fn (): string => $this->engine->render(FrontendController::template($this->engine, $route), [
            'record' => $draft->localized($locale)->toArray(),
            'rendered' => $this->records->renderedMap($draft, $locale),
            'type' => $type,
            'preview' => true,
            'locale' => $locale,
            'alternates' => [],
        ]));

        $html = $this->assets?->injectInto($html) ?? $html;

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], self::withEditorBridge($html, $this->assetUrls));
    }

    /**
     * $html with the preview-only editor bridge (hover outlines, click-to-edit) and $bodyEnd (the
     * whole-page preview's bar: markup without scripts or styles of its own) before </body>.
     * External same-origin files, so the page's own CSP allows them without a nonce.
     *
     * @internal Folio's previews
     */
    public static function withEditorBridge(string $html, ?AdminAssetUrls $assetUrls, string $bodyEnd = ''): string
    {
        if ($assetUrls === null) {
            return $html;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $css = '<link rel="stylesheet" href="' . $e($assetUrls('preview-editor.css')) . '">' . "\n";
        $js = $bodyEnd . '<script src="' . $e($assetUrls('preview-editor.js')) . '" defer></script>' . "\n";

        $html = ($pos = stripos($html, '</head>')) !== false ? substr_replace($html, $css, $pos, 0) : $css . $html;

        return ($pos = strripos($html, '</body>')) !== false ? substr_replace($html, $js, $pos, 0) : $html . $js;
    }

}
