<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\Locales;
use Preflow\Fields\EmbedOptions;
use Preflow\Fields\EmbedOutput;
use Preflow\Fields\EmbedProvider;
use Preflow\Fields\EmbedProviders;
use Preflow\Fields\Values\Embed;
use Preflow\Fields\Values\Link;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\FolioText;

/**
 * A video from YouTube, Vimeo or a host the site added (0.91; the value, check, storage and page
 * output are preflow/fields': Values\Embed, EmbedOptions, ValidEmbed, EmbedOutput). The editor
 * pastes the video's address from the browser; under it the editor says which providers the field
 * takes and, for a saved video, where it plays from. An optional poster is chosen with the asset
 * field's picker (an image of the media library), shown on the page before the video plays.
 *
 * Posted: {name}[url], {name}[poster_ids][] (the picker) or an upload under {name}[poster].
 * Stored: Embed::toStored()'s object, or null for neither an address nor a poster. An address that
 * is no video is kept as typed (provider and id null) so the core check names what is wrong.
 *
 * The page gets the core's privacy player (EmbedOutput: the bound EmbedPlayer, nothing loaded from
 * the video's host before a click). The check is the core's: rules() adds none.
 */
final class EmbedFieldType implements FieldType, StructuredFieldType, DisplaysInAdmin
{
    /** One image, public: what the poster picker takes. */
    private const array POSTER_CONFIG = ['asset' => ['multiple' => false, 'accept' => 'image/*']];

    /**
     * @param (\Closure(): EmbedProviders)|null $providers the app's video hosts (default: YouTube and Vimeo)
     * @param EmbedOutput|null $output the page's player (null: the page shows a link to the video)
     */
    public function __construct(
        private readonly ?FieldTypeRegistry $fieldTypes = null,
        private readonly ?\Closure $providers = null,
        private readonly ?EmbedOutput $output = null,
    ) {}

    public function key(): string
    {
        return 'embed';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = static fn (string $key, array $params = []): string => $e(FolioText::get('folio.fields.embed.' . $key, $params));
        $data = self::decode($ctx->value) ?? [];
        $url = is_string($data['url'] ?? null) ? $data['url'] : '';
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = 'folio-embed-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ctx->name), '-');
        $hasError = $ctx->errors !== [];
        $providers = $this->providers();
        $offered = implode(', ', array_map(static fn (EmbedProvider $p): string => $p->label(), $this->offered($providers, $ctx->config)));

        $html = '<fieldset class="form-group folio-embed' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        $html .= '  <div class="form-group"><label for="' . $e($id) . '-url">' . $t('url') . '</label>'
            . '<input type="url" id="' . $e($id) . '-url" name="' . $e($ctx->name) . '[url]" value="' . $e($url) . '" placeholder="https://…" inputmode="url" autocomplete="off" aria-describedby="' . $e($id) . '-hint">';
        $embed = Embed::fromStored($data);
        $provider = $embed !== null && !$hasError ? $providers->get($embed->provider) : null;
        $hint = $provider !== null
            ? $t('plays_from', ['provider' => $provider->label(), 'id' => $embed->id])
            : $t('hint', ['providers' => $offered]);
        $html .= '<small class="form-help" id="' . $e($id) . '-hint">' . $hint . '</small></div>' . "\n";
        $html .= $this->posterPicker($ctx, $embed->poster ?? (is_string($data['poster'] ?? null) ? $data['poster'] : ''));
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /** The poster part: the asset field's single picker for one image under {name}[poster]. */
    private function posterPicker(FieldContext $ctx, string $poster): string
    {
        $asset = $this->assetType();
        if ($asset === null) {
            return '';
        }

        return '  <div class="folio-embed-poster">' . $asset->renderEditor(new FieldContext(
            name: $ctx->name . '[poster]',
            label: FolioText::get('folio.fields.embed.poster'),
            help: FolioText::get('folio.fields.embed.poster_help'),
            value: $poster,
            config: self::POSTER_CONFIG,
            locale: $ctx->locale,
            locales: $ctx->locales,
        )) . "</div>\n";
    }

    /**
     * The pasted address as a video of an offered provider, with the chosen poster. Neither an
     * address nor a poster: null. An address that is no video stays as typed for the check.
     *
     * @return array<string, mixed>|null
     */
    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx, array $config = []): mixed
    {
        $input = is_array($input) ? $input : [];
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
        $poster = $this->posterTarget($input, $existing, $files, $ctx);
        if ($url === '' && $poster === null) {
            return null;
        }
        $embed = $url === '' ? null : Embed::fromUrl($url, $this->providers(), EmbedOptions::fromConfig($config)->providers, $poster);

        return $embed?->toStored() ?? ['provider' => null, 'id' => null, 'url' => $url, 'poster' => $poster];
    }

    /**
     * The chosen poster through the asset field's resolve(): the posted selection
     * ({name}[poster_ids]) or an upload ({name}[poster]); without a posted selection the stored one stays.
     *
     * @param array<mixed> $input
     * @param array<mixed> $files
     */
    private function posterTarget(array $input, mixed $existing, array $files, BuildContext $ctx): ?string
    {
        $asset = $this->assetType();
        $stored = self::decode($existing);
        $current = is_string($stored['poster'] ?? null) ? $stored['poster'] : '';
        if ($asset === null) {
            return $current === '' ? null : $current;
        }
        $selection = $input['poster_ids'] ?? null;
        $uploads = array_values(array_filter(
            is_array($files['poster'] ?? null) ? $files['poster'] : [$files['poster'] ?? null],
            static fn (mixed $f): bool => $f instanceof \Psr\Http\Message\UploadedFileInterface && $f->getError() === UPLOAD_ERR_OK,
        ));
        $resolved = $asset->resolve(is_array($selection) ? array_values($selection) : null, $current, $uploads, self::POSTER_CONFIG, $ctx->writeUploads, null);
        $id = is_string($resolved) ? $resolved : (string) ($resolved[0] ?? '');

        return $id === '' ? null : $id;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (is_string($raw)) {
            $raw = ['url' => $raw];
        }

        return $this->buildValue($raw, null, [], new BuildContext(Locales::single('en'), 'en', false), $config);
    }

    /** The stored object as it is (SQL storage writes JSON text of it); a plain address stays text; null for no video. */
    public function toStorage(mixed $value): mixed
    {
        return is_string($value) && !str_starts_with(ltrim($value), '{') ? (trim($value) === '' ? null : trim($value)) : self::decode($value);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->toStorage($value);
    }

    /** The core's player (EmbedOutput) in the render's locale; without one a link to the video. '' for no video. */
    public function renderFrontend(mixed $value, array $config): string
    {
        $locale = \Preflow\Core\Context\Contexts::current()->locale;
        if ($this->output !== null) {
            return $this->output->html($value, null, $locale);
        }
        $embed = Embed::fromStored($value, $this->providers());
        if ($embed === null || !Link::isSiteOrWebAddress($embed->url)) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<a href="' . $e($embed->url) . '" rel="noopener noreferrer">' . $e($embed->url) . '</a>';
    }

    /**
     * "YouTube · aqz-KE-bpKQ" for a list cell or a read-only field; the address for one that is no video.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $providers = $this->providers();
        $embed = Embed::fromStored($value, $providers);
        $text = $embed !== null ? ($providers->get($embed->provider)?->label() ?? $embed->provider) . ' · ' . $embed->id : (string) (self::decode($value)['url'] ?? '');

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** None of its own: the core's check (ValidEmbed, through FieldKinds::rules()) applies in Folio too. */
    public function rules(array $config): array
    {
        return [];
    }

    /** fields.js runs the poster picker's file drop; admin.js (every editor loads it) its Choose and Remove. */
    public function assets(): array
    {
        return ['fields.js'];
    }

    private function providers(): EmbedProviders
    {
        return $this->providers !== null ? ($this->providers)() : new EmbedProviders();
    }

    /**
     * The providers the field offers; a listed name the site never added is left out here (the
     * core check names it on save).
     *
     * @param array<string, mixed> $config
     * @return list<EmbedProvider>
     */
    private function offered(EmbedProviders $providers, array $config): array
    {
        $listed = array_values(array_filter(EmbedOptions::fromConfig($config)->providers, $providers->has(...)));

        return $providers->offered($listed);
    }

    /**
     * A stored or posted value as its object: the object, JSON text of it, or a plain address as {url}.
     *
     * @return array<mixed>|null
     */
    private static function decode(mixed $value): ?array
    {
        if (is_string($value) && !str_starts_with(ltrim($value), '{')) {
            return trim($value) === '' ? null : ['url' => trim($value)];
        }

        return Link::decode($value);
    }

    private function assetType(): ?AssetFieldType
    {
        $asset = $this->fieldTypes !== null && $this->fieldTypes->has('asset') ? $this->fieldTypes->get('asset') : null;

        return $asset instanceof AssetFieldType ? $asset : null;
    }
}
