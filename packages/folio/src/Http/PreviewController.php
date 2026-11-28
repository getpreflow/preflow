<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Blocks\Preview\PreviewCanvas;
use Preflow\Blocks\Preview\TypePreview;
use Preflow\Blocks\Preview\TypePreviews;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
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
 *
 * A type with "preview" (since 0.94.0, TypePreviews) previews too, with or without a route: the form
 * posts the view in _preview_view, and a view key of the type's preview renders the draft through
 * that view's component on the canvas @folio/frontend/preview-canvas.twig (PreviewCanvas, which a
 * site overrides at resources/folio/frontend/preview-canvas.twig), in the same context. "page", or
 * a key the type lacks, is the route's template; without a route, the first view.
 */
final class PreviewController
{
    private readonly DraftRecordFactory $drafts;
    private readonly \Preflow\Folio\Content\DisplayLabels $labels;

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly RecordRenderer $records,
        private readonly TemplateEngineInterface $engine,
        private readonly FrontendResolver $frontend,
        ?DraftRecordFactory $drafts = null,
        private readonly ?\Preflow\View\AssetCollector $assets = null,
        private readonly ?AdminAssetUrls $assetUrls = null,
        // The types' preview views and the canvas they render on (since 0.94.0); null: only routed types preview.
        private readonly ?TypePreviews $previews = null,
        private readonly ?PreviewCanvas $canvas = null,
        ?\Preflow\Folio\Content\DisplayLabels $labels = null,
    ) {
        $this->drafts = $drafts ?? new DraftRecordFactory($fieldTypes);
        $this->labels = $labels ?? new \Preflow\Folio\Content\DisplayLabels($catalog, $registry);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $route = $this->frontend->routeFor($type);
        $typePreview = $this->catalog->has($type) ? $this->typePreview($type) : null;
        if (!$this->catalog->has($type) || ($route === null && $typePreview === null)) {
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

        // A view of the type's preview: its component on the canvas, in the same context.
        $view = self::chosenView($request, $typePreview, $route !== null);
        if ($typePreview !== null && $this->canvas !== null && $view !== null) {
            // The type's label in the admin's language, before the visitor's context.
            $label = $this->labels->type($type);
            $canvas = $this->canvas;
            $html = Contexts::within($preview, fn (): string => $canvas->render(
                $typePreview,
                $view,
                $this->records->values($draft, $locale),
                $locale,
                self::CANVAS,
                ['label' => $label],
            ));

            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], self::withEditorBridge($html, $this->assetUrls));
        }
        if ($route === null) {
            // Not reached: a type without a route has a preview (checked above), whose first view rendered.
            return NotFound::response($request, \Preflow\Folio\FolioText::get('folio.errors.not_found'));
        }

        // The draft's layout (0.92): the one chosen in the form, with its areas as posted.
        $layout = $this->records->layoutFor($draft);
        $html = Contexts::within($preview, fn (): string => $this->engine->render(FrontendController::template($this->engine, $route, $layout?->template()), [
            'record' => $this->records->values($draft, $locale),
            'rendered' => $this->records->renderedMap($draft, $locale),
            'type' => $type,
            'preview' => true,
            'locale' => $locale,
            'alternates' => [],
            ...($layout?->context() ?? []),
        ]));

        $html = $this->assets?->injectInto($html) ?? $html;

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], self::withEditorBridge($html, $this->assetUrls));
    }

    /** The canvas a type preview renders on; a site overrides it at resources/folio/frontend/preview-canvas.twig. @since 0.94.0 */
    public const string CANVAS = '@folio/frontend/preview-canvas.twig';

    /**
     * The view the form chose (_preview_view) as a key of the type's preview, or null for the route's
     * template: "page", nothing, or a key the type lacks is the route's template where the type has a
     * route, else the first view.
     */
    private static function chosenView(ServerRequestInterface $request, ?TypePreview $typePreview, bool $routed): ?string
    {
        if ($typePreview === null) {
            return null;
        }
        $chosen = ((array) $request->getParsedBody())['_preview_view'] ?? null;
        if (is_string($chosen) && in_array($chosen, $typePreview->keys(), true)) {
            return $chosen;
        }

        return $routed ? null : $typePreview->keys()[0];
    }

    private function typePreview(string $type): ?TypePreview
    {
        return $this->canvas !== null ? $this->previews?->forType($type) : null;
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
