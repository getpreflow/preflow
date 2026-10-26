<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\Locales;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Media\Asset;
use Preflow\Media\AssetUploadException;
use Preflow\Media\AssetVersion;
use Preflow\Media\Color\ColorRules;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\PosterReferences;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * {prefix}/assets: the media library. Grid/list, search, kind filter, the filters of
 * config/media.php ("filters"), multi-file upload, a metadata panel (alt per language,
 * title, focal point, video poster, the extra fields with Folio's field types) with usage,
 * colour swatches, replace and the version list (open, restore, delete), and delete (the
 * library refuses it while used). With ?picker=1 it renders in the drawer layout and each
 * asset gets a "Select" button. Every change goes through MediaLibrary.
 */
final class AssetLibraryController
{
    private const int PER_PAGE = 48;

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly AssetUsage $usage,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly Locales $locales,
        private readonly string $prefix,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly MediaConfig $media,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request, []);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $picker = ($body['picker'] ?? '') === '1';
        $private = $picker ? self::privacy($body) : null;
        $by = self::by($request);

        $files = $request->getUploadedFiles()['files'] ?? [];
        $files = $files instanceof UploadedFileInterface ? [$files] : (is_array($files) ? $files : []);
        $created = [];
        $errors = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $created[] = $this->library->upload($file, $by, private: $private === true)->id;
            } catch (AssetUploadException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->renderIndex($request, $errors, 422, $created);
        }

        $location = $this->prefix . '/assets';
        if ($picker) {
            $location .= '?picker=1&multiple=' . (($body['multiple'] ?? '') === '1' ? '1' : '0')
                . ($private !== null ? '&private=' . ($private ? '1' : '0') : '') . '&selected=' . implode(',', $created);
        }

        return new Response(302, ['Location' => $location]);
    }

    public function panel(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], 'Not found');
        }

        $query = $request->getQueryParams();
        $notice = match (true) {
            ($query['saved'] ?? null) === '1' => 'Saved.',
            ($query['done'] ?? null) === 'replaced' => 'Replaced.',
            ($query['done'] ?? null) === 'restored' => 'Restored version ' . (int) ($query['version'] ?? 0) . '.',
            ($query['done'] ?? null) === 'version-deleted' => 'Version deleted.',
            default => null,
        };

        return $this->renderPanel($request, $asset, $notice);
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
        // The panel's form carries every extra field (an unchecked box or an empty multi relation posts nothing).
        if (($body['_extra'] ?? null) === '1') {
            foreach ($this->extraFields() as $name => $field) {
                $meta[$name] = $this->fieldTypes->get($field->type)->normalizeInput($body[$name] ?? null, $field->config);
            }
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
        try {
            $this->library->delete($asset->id);
        } catch (AssetInUseException $e) {
            $n = count($e->usages);
            return $this->renderPanel($request, $asset, null, 'Used in ' . $n . ' record' . ($n === 1 ? '' : 's') . ' — remove it there first.', 409, $e->usages);
        }

        return new Response(302, ['Location' => $this->prefix . '/assets']);
    }

    /** A new file for the asset (same kind); the old one becomes a version. Errors show in the panel. */
    public function replace(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], 'Not found');
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->renderPanel($request, $asset, null, 'Choose a file to replace this one with.', 422);
        }
        try {
            $this->library->replace($asset->id, $file, self::by($request));
        } catch (AssetUploadException $e) {
            return $this->renderPanel($request, $asset, null, $e->getMessage(), 422);
        }

        return $this->backToPanel($asset, 'done=replaced');
    }

    public function restoreVersion(ServerRequestInterface $request): ResponseInterface
    {
        [$asset, $version] = $this->version($request);
        if ($asset === null || $version === null) {
            return new Response(404, [], 'Not found');
        }
        try {
            $this->library->restore($asset->id, $version->number, self::by($request));
        } catch (AssetUploadException $e) {
            return $this->renderPanel($request, $asset, null, $e->getMessage(), 422);
        }

        return $this->backToPanel($asset, 'done=restored&version=' . $version->number);
    }

    public function deleteVersion(ServerRequestInterface $request): ResponseInterface
    {
        [$asset, $version] = $this->version($request);
        if ($asset === null || $version === null) {
            return new Response(404, [], 'Not found');
        }
        $this->library->deleteVersion($asset->id, $version->number);

        return $this->backToPanel($asset, 'done=version-deleted');
    }

    /** @return array{?Asset, ?AssetVersion} the asset and its version {n} from the route */
    private function version(ServerRequestInterface $request): array
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        $n = (string) $request->getAttribute('n', '');
        if ($asset === null || preg_match('/^[1-9][0-9]{0,5}$/', $n) !== 1) {
            return [$asset, null];
        }
        foreach ($this->library->versions($asset->id) as $version) {
            if ($version->number === (int) $n) {
                return [$asset, $version];
            }
        }

        return [$asset, null];
    }

    private function backToPanel(Asset $asset, string $query): ResponseInterface
    {
        return new Response(302, ['Location' => $this->prefix . '/assets/' . $asset->id . '?' . $query]);
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
        $private = $picker ? self::privacy($params) : null; // a field's picker lists only what the field accepts
        $page = max(1, (int) ($params['page'] ?? 1));
        if ($selected === [] && is_string($params['selected'] ?? null)) {
            $selected = array_values(array_filter(explode(',', $params['selected'])));
        }

        $filters = $this->filterValues($params['f'] ?? null);

        $result = $this->library->query($kind === '' ? null : $kind, $q, $filters + ($private === null ? [] : ['private' => $private]), $page, self::PER_PAGE);
        $total = $result->total;
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $result->page;

        return $this->html($this->engine->render('@folio/admin/assets/index.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'layout' => $picker ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig',
            'editor_assets' => $picker ? ['admin.js'] : ['admin.js', 'htmx.js'],
            'items' => array_map(fn (Asset $a) => $this->item($a), $result->items),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'kind' => $kind,
            'filters' => $this->filterControls($filters),
            'filtered' => $filters !== [],
            'view' => $view,
            'picker' => $picker,
            'multiple' => $multiple,
            'private' => $private,
            'selected' => $selected,
            'errors' => $errors,
            'csrf' => self::csrf($request),
            'query' => array_filter(['q' => $q, 'kind' => $kind, 'f' => $filters, 'picker' => $picker ? '1' : '', 'multiple' => $picker ? ($multiple ? '1' : '0') : '', 'private' => $private === null ? '' : ($private ? '1' : '0')], static fn ($v) => $v !== ''),
        ]), $status);
    }

    /** @param list<AssetUsageEntry>|null $usage */
    private function renderPanel(ServerRequestInterface $request, Asset $asset, ?string $notice, ?string $error = null, int $status = 200, ?array $usage = null): ResponseInterface
    {
        $posters = $asset->kind() === 'video'
            ? array_values(array_filter($this->library->all(), static fn (Asset $a) => $a->kind() === 'image'))
            : [];
        [$fields, $fieldAssets] = $this->extraEditors($asset);
        $context = [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'editor_assets' => array_values(array_unique(['admin.js', ...$fieldAssets])),
            'asset' => $this->item($asset),
            'fields' => $fields,
            'versions' => array_map(fn (AssetVersion $v) => $this->versionRow($asset, $v), $this->library->versions($asset->id)),
            'swatches' => self::swatches($asset),
            'note' => $asset->note,
            'raw' => $asset,
            'locales' => $this->locales->available,
            'posters' => array_map(fn (Asset $a) => $this->item($a), $posters),
            'usage' => array_map(self::usageRow(...), $usage ?? $this->usage->of($asset->id)),
            'notice' => $notice,
            'error' => $error,
            'csrf' => self::csrf($request),
        ];
        $template = $request->getHeaderLine('HX-Request') === 'true' ? '@folio/admin/assets/panel.twig' : '@folio/admin/assets/panel_page.twig';

        return $this->html($this->engine->render($template, $context), $status);
    }

    /**
     * A usage entry as the panel template's row: where it is ("working",
     * "published" from Folio's note, "poster" from the core's poster source;
     * other sources' entries count as working copies).
     *
     * @return array{label: string, url: string, where: string, source: string, note: ?string}
     */
    private static function usageRow(AssetUsageEntry $entry): array
    {
        return [
            'label' => $entry->label,
            'url' => $entry->url ?? '',
            'where' => match ($entry->note) {
                FolioRecordReferences::PUBLISHED => 'published',
                PosterReferences::NOTE => 'poster',
                default => 'working',
            },
            'source' => $entry->source,
            'note' => $entry->note,
        ];
    }

    /** @return array<string, TypeFieldDefinition> the extra fields of config/media.php */
    private function extraFields(): array
    {
        return array_filter(
            $this->library->definition()->fields,
            static fn (TypeFieldDefinition $f) => !in_array($f->name, Asset::RESERVED, true),
        );
    }

    /**
     * The extra fields' editors, rendered by Folio's field types as in the record form,
     * and the admin assets those types need.
     *
     * @return array{list<string>, list<string>}
     */
    private function extraEditors(Asset $asset): array
    {
        $html = [];
        $assets = [];
        foreach ($this->extraFields() as $name => $field) {
            $type = $this->fieldTypes->get($field->type);
            $html[] = $type->renderEditor(new FieldContext(
                name: $name,
                label: $field->label ?? ucfirst(str_replace('_', ' ', $name)),
                help: $field->help,
                value: $type->fromStorage($asset->extra[$name] ?? null),
                config: $field->config,
                required: in_array('required', $field->validate, true),
                locale: $this->locales->default,
                locales: $this->locales,
            ));
            $assets = [...$assets, ...$type->assets()];
        }

        return [$html, $assets];
    }

    /**
     * The toolbar's values: config "filters" names from the request's f[…], text only.
     *
     * @return array<string, string>
     */
    private function filterValues(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($this->media->filters as $name) {
            $value = $raw[$name] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 255) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * One toolbar select per configured filter: a select field's options, a relation's
     * targets (as its field type lists them; a large target searches instead) or yes/no.
     *
     * @param array<string, string> $values
     * @return list<array{name: string, label: string, value: string, options: array<string, string>, html: ?string}>
     */
    private function filterControls(array $values): array
    {
        $fields = $this->extraFields();
        $out = [];
        foreach ($this->media->filters as $name) {
            $field = $fields[$name] ?? null;
            if ($field === null) {
                continue;
            }
            $label = $field->label ?? ucfirst(str_replace('_', ' ', $name));
            $value = $values[$name] ?? '';
            $options = [];
            $html = null;
            if ($field->type === 'boolean') {
                $options = ['1' => 'Yes', '0' => 'No'];
            } elseif ($field->type === 'select') {
                $raw = is_array($field->config['options'] ?? null) ? $field->config['options'] : [];
                foreach ($raw as $key => $text) {
                    $options[array_is_list($raw) ? (string) $text : (string) $key] = (string) $text;
                }
            } elseif ($field->type === 'relation') {
                $type = $this->fieldTypes->get('relation');
                $config = ['relation' => ['multiple' => false] + (is_array($field->config['relation'] ?? null) ? $field->config['relation'] : [])];
                $choices = $type instanceof RelationFieldType ? $type->choices($config) : null;
                if ($choices === null) {
                    $html = $type->renderEditor(new FieldContext(name: 'f[' . $name . ']', label: $label, value: $value, config: $config, locale: $this->locales->default, locales: $this->locales));
                } else {
                    $options = $choices;
                }
            }
            $out[] = ['name' => $name, 'label' => $label, 'value' => $value, 'options' => $options, 'html' => $html];
        }

        return $out;
    }

    /** @return array<string, mixed> a version list row; its file is the core's gated {private_url}/{id}/v/{n} */
    private function versionRow(Asset $asset, AssetVersion $version): array
    {
        return [
            'number' => $version->number,
            'created_at' => $version->createdAt,
            'size' => $version->size,
            'width' => $version->width,
            'height' => $version->height,
            'mime' => $version->mime,
            'url' => rtrim($this->media->privateUrl, '/') . '/' . $asset->id . '/v/' . $version->number,
        ];
    }

    /**
     * The dominant colour and the palette as swatches (hex and "rgb(r, g, b)" to copy); [] without colours.
     *
     * @return list<array{label: string, hex: string, rgb: string}>
     */
    private static function swatches(Asset $asset): array
    {
        $data = ColorRules::data($asset);
        if ($data === null) {
            return [];
        }
        $rgb = static fn (string $triple): string => 'rgb(' . str_replace(',', ', ', $triple) . ')';
        $out = [['label' => 'Dominant', 'hex' => $data['color'], 'rgb' => $rgb($data['rgb'])]];
        foreach ($data['palette'] as $i => $hex) {
            $out[] = ['label' => 'Palette ' . ($i + 1), 'hex' => $hex, 'rgb' => $rgb($data['palette_rgb'][$i])];
        }

        return $out;
    }

    private static function by(ServerRequestInterface $request): ?string
    {
        $user = $request->getAttribute(\Preflow\Auth\Authenticatable::class);

        return $user instanceof \Preflow\Auth\Authenticatable ? $user->getAuthId() : null;
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
            'url' => $this->library->url($asset),
            'thumb' => $this->library->thumbnailUrl($asset),
            'alt' => $asset->alt,
            'title' => $asset->title,
            'focal_x' => $asset->focalX,
            'focal_y' => $asset->focalY,
            'poster' => $asset->posterId,
            'created_at' => $asset->createdAt,
        ];
    }

    /**
     * The picker's "private" parameter: true or false, null when absent (any).
     *
     * @param array<mixed> $params
     */
    private static function privacy(array $params): ?bool
    {
        return match ($params['private'] ?? null) {
            '1' => true,
            '0' => false,
            default => null,
        };
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
