<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\Locales;
use Preflow\Folio\Assets\Asset;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Assets\AssetUploadException;
use Preflow\Folio\Assets\AssetUsage;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * {prefix}/assets: the media library. Grid/list, search, kind filter,
 * multi-file upload, a metadata panel (alt per language, title, focal point,
 * video poster) with usage, and delete (refused while used). With ?picker=1
 * it renders in the drawer layout and each asset gets a "Select" button.
 */
final class AssetLibraryController
{
    private const int PER_PAGE = 48;

    public function __construct(
        private readonly AssetLibrary $library,
        private readonly AssetUsage $usage,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly Locales $locales,
        private readonly string $prefix,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request, []);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $picker = ($body['picker'] ?? '') === '1';
        $user = $request->getAttribute(\Preflow\Auth\Authenticatable::class);
        $by = $user instanceof \Preflow\Auth\Authenticatable ? $user->getAuthId() : null;

        $files = $request->getUploadedFiles()['files'] ?? [];
        $files = $files instanceof UploadedFileInterface ? [$files] : (is_array($files) ? $files : []);
        $created = [];
        $errors = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $created[] = $this->library->upload($file, $by)->id;
            } catch (AssetUploadException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->renderIndex($request, $errors, 422, $created);
        }

        $location = $this->prefix . '/assets';
        if ($picker) {
            $location .= '?picker=1&multiple=' . (($body['multiple'] ?? '') === '1' ? '1' : '0') . '&selected=' . implode(',', $created);
        }

        return new Response(302, ['Location' => $location]);
    }

    public function panel(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], 'Not found');
        }

        return $this->renderPanel($request, $asset, ($request->getQueryParams()['saved'] ?? null) === '1' ? 'Saved.' : null);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], 'Not found');
        }
        $body = (array) $request->getParsedBody();
        $meta = array_intersect_key($body, array_flip(['alt', 'title', 'focal_x', 'focal_y']));
        if ($asset->kind() === 'video') {
            $meta['poster'] = $body['poster'] ?? '';
        }
        $this->library->update($asset->id, $meta);

        return new Response(302, ['Location' => $this->prefix . '/assets/' . $asset->id . '?saved=1']);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], 'Not found');
        }
        $usage = $this->usage->of($asset->id);
        if ($usage !== []) {
            $n = count($usage);
            return $this->renderPanel($request, $asset, null, 'Used in ' . $n . ' record' . ($n === 1 ? '' : 's') . ' — remove it there first.', 409, $usage);
        }
        $this->library->delete($asset->id);

        return new Response(302, ['Location' => $this->prefix . '/assets']);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $selected
     */
    private function renderIndex(ServerRequestInterface $request, array $errors, int $status = 200, array $selected = []): ResponseInterface
    {
        $params = array_merge($request->getQueryParams(), (array) $request->getParsedBody());
        $q = trim(is_string($params['q'] ?? null) ? $params['q'] : '');
        $kind = in_array($params['kind'] ?? null, ['image', 'video', 'document'], true) ? $params['kind'] : '';
        $view = ($params['view'] ?? '') === 'list' ? 'list' : 'grid';
        $picker = ($params['picker'] ?? '') === '1';
        $multiple = ($params['multiple'] ?? '') === '1';
        $page = max(1, (int) ($params['page'] ?? 1));
        if ($selected === [] && is_string($params['selected'] ?? null)) {
            $selected = array_values(array_filter(explode(',', $params['selected'])));
        }

        $assets = array_values(array_filter($this->library->all(), static fn (Asset $a) => ($kind === '' || $a->kind() === $kind) && self::matches($a, $q)));
        $total = count($assets);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        return $this->html($this->engine->render('@folio/admin/assets/index.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'layout' => $picker ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig',
            'editor_assets' => $picker ? ['admin.js'] : ['admin.js', 'htmx.js'],
            'items' => array_map(fn (Asset $a) => $this->item($a), array_slice($assets, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'kind' => $kind,
            'view' => $view,
            'picker' => $picker,
            'multiple' => $multiple,
            'selected' => $selected,
            'errors' => $errors,
            'csrf' => self::csrf($request),
            'query' => array_filter(['q' => $q, 'kind' => $kind, 'picker' => $picker ? '1' : '', 'multiple' => $picker ? ($multiple ? '1' : '0') : ''], static fn ($v) => $v !== ''),
        ]), $status);
    }

    /** @param list<array<string, string>> $usage */
    private function renderPanel(ServerRequestInterface $request, Asset $asset, ?string $notice, ?string $error = null, int $status = 200, ?array $usage = null): ResponseInterface
    {
        $posters = $asset->kind() === 'video'
            ? array_values(array_filter($this->library->all(), static fn (Asset $a) => $a->kind() === 'image'))
            : [];
        $context = [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'editor_assets' => ['admin.js'],
            'asset' => $this->item($asset),
            'raw' => $asset,
            'locales' => $this->locales->available,
            'posters' => array_map(fn (Asset $a) => $this->item($a), $posters),
            'usage' => $usage ?? $this->usage->of($asset->id),
            'notice' => $notice,
            'error' => $error,
            'csrf' => self::csrf($request),
        ];
        $template = $request->getHeaderLine('HX-Request') === 'true' ? '@folio/admin/assets/panel.twig' : '@folio/admin/assets/panel_page.twig';

        return $this->html($this->engine->render($template, $context), $status);
    }

    /** @return array<string, mixed> */
    private function item(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'label' => $asset->label(),
            'name' => $asset->originalName,
            'kind' => $asset->kind(),
            'mime' => $asset->mime,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $this->library->url($asset->path),
            'thumb' => $this->library->thumbnailUrl($asset),
            'alt' => $asset->alt,
            'title' => $asset->title,
            'focal_x' => $asset->focalX,
            'focal_y' => $asset->focalY,
            'poster' => $asset->posterId,
            'created_at' => $asset->createdAt,
        ];
    }

    private static function matches(Asset $asset, string $q): bool
    {
        if ($q === '') {
            return true;
        }
        foreach ([$asset->originalName, $asset->title, ...array_values($asset->alt)] as $text) {
            if (mb_stripos($text, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function csrf(ServerRequestInterface $request): string
    {
        return $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
    }

    private function html(string $html, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
