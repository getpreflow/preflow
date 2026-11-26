<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Fields\LinkOptions;
use Preflow\Fields\RecordAddresses;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\LinkKind;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\ShowWhenMarkup;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\FolioText;

/**
 * The link field's input (0.91; the value, its checks and storage are preflow/fields' Link,
 * LinkOptions and ValidLink). The editor picks what the link points to ("Links to"): a record,
 * found with the relation picker (rows plus a search: the embedded options of a small type, the
 * search endpoint {prefix}/_relation/{type} of a large one), a file chosen with the asset field's
 * picker (the media library in the drawer, or an upload), or an address, email address or phone
 * number typed in; then the link text (per language) and "Open in a new tab". The
 * parts for the other kinds are hidden with the conditional-field markup (show-when-core.js, so
 * they show without a round trip); each kind posts under its own name, so the server takes the
 * chosen kind's target also without script.
 *
 * Posted: {name}[kind], {name}[type], {name}[record][{type}], {name}[url|email|phone],
 * {name}[asset_ids][] (and an upload under {name}[asset]), {name}[label], {name}[new_tab]. Stored: Link::toStored()'s object, or null for no link. A
 * chosen kind without a target is kept as it is, so ValidLink names what is missing.
 *
 * The checks are the core's (ValidLink, through FieldKinds::rules()): rules() adds none.
 *
 * A record or file link that a visitor's page renders without an href (the record is gone,
 * unpublished or not in the language; the file is gone) is marked in the editor ("Linked page no
 * longer available"): the mark is decided by the same href, read as a visitor reads.
 */
final class LinkFieldType implements FieldType, StructuredFieldType, DisplaysInAdmin, ReportsTranslatableTexts, AcceptsTranslatedTexts, \Preflow\Folio\Field\ReferencesAssets
{
    /**
     * @param (\Closure(): list<string>)|null $linkableTypes the types a field without "link.types" offers (Folio: the routed ones)
     * @param (\Closure(string): string)|null $typeLabel a type's label for one record ("Page")
     * @param (\Closure(): ?RecordAddresses)|null $addresses the bound RecordAddresses: the frontend's href and the editor's mark
     * @param (\Closure(): ?Locales)|null $locales the content locales, for the label's fallback on the frontend
     */
    public function __construct(
        private readonly ?FieldTypeRegistry $fieldTypes = null,
        private readonly ?\Closure $linkableTypes = null,
        private readonly ?\Closure $typeLabel = null,
        private readonly ?\Closure $addresses = null,
        private readonly ?\Closure $locales = null,
    ) {}

    public function key(): string
    {
        return 'link';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $options = LinkOptions::fromConfig($ctx->config);
        $data = Link::decode($ctx->value) ?? [];
        $link = Link::fromStored($data);
        $kind = is_string($data['kind'] ?? null) ? (LinkKind::tryFrom($data['kind'])->value ?? '') : '';
        $types = $this->offeredTypes($options);
        $currentType = $link?->recordType() ?? $types[0] ?? '';
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = 'folio-link-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ctx->name), '-');
        $locale = $ctx->locale ?? $ctx->locales->default ?? 'en';
        $t = static fn (string $key, array $params = []): string => $e(FolioText::get('folio.fields.link.' . $key, $params));
        $hasError = $ctx->errors !== [];

        $html = '<fieldset class="form-group folio-link' . ($hasError ? ' has-error' : '') . '" data-folio-link>' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        if ($link !== null && $this->isBroken($link, $locale)) {
            $html .= '  <p class="folio-link-broken" role="status">' . $t($link->kind === LinkKind::Asset ? 'broken_asset' : 'broken') . ' ' . $t('broken_hint') . '</p>' . "\n";
        }

        // What the link points to; "No link" keeps the field empty.
        $html .= '  <div class="form-group"><label for="' . $e($id) . '-kind">' . $t('kind') . '</label><select id="' . $e($id) . '-kind" name="' . $e($ctx->name) . '[kind]">';
        $html .= '<option value=""' . ($kind === '' ? ' selected' : '') . '>' . $t('none') . '</option>';
        $offered = $options->kinds;
        if ($kind !== '' && !in_array(LinkKind::from($kind), $offered, true)) {
            $offered[] = LinkKind::from($kind); // a stored kind the field no longer offers stays visible (the check refuses it)
        }
        foreach ($offered as $choice) {
            $html .= '<option value="' . $choice->value . '"' . ($choice->value === $kind ? ' selected' : '') . '>' . $t('kinds.' . $choice->value) . '</option>';
        }
        $html .= '</select></div>' . "\n";

        $fields = ['kind' => new TypeFieldDefinition('kind', 'select'), 'type' => new TypeFieldDefinition('type', 'select')];
        $values = ['kind' => $kind, 'type' => $currentType];
        $part = static function (array $rule, string $inner) use ($fields, $values, $ctx): string {
            $hidden = !\Preflow\Data\FieldVisibility::fromConfig($rule, array_map(static fn (TypeFieldDefinition $f): string => $f->type, $fields))->holds($values);

            return '  <div class="folio-show-when folio-link-part"' . ShowWhenMarkup::attributes($rule, $fields, $values, $ctx->name, $hidden) . '>' . $inner . '</div>' . "\n";
        };

        if ($options->allows(LinkKind::Record) || $kind === LinkKind::Record->value) {
            $html .= $part(['field' => 'kind', '=' => 'record'], $this->recordPicker($ctx, $types, $currentType, $link, $id, $part));
        }
        if ($options->allows(LinkKind::Asset) || $kind === LinkKind::Asset->value) {
            $html .= $part(['field' => 'kind', '=' => 'asset'], $this->assetPicker($ctx, $link));
        }
        foreach ([[LinkKind::Url, 'text'], [LinkKind::Email, 'email'], [LinkKind::Phone, 'tel']] as [$textKind, $inputType]) {
            if (!$options->allows($textKind) && $textKind->value !== $kind) {
                continue;
            }
            $value = $link !== null && $link->kind === $textKind && is_string($link->target) ? $link->target : (($data['kind'] ?? null) === $textKind->value && is_string($data['target'] ?? null) ? $data['target'] : '');
            $input = '<div class="form-group"><label for="' . $e($id) . '-' . $textKind->value . '">' . $t($textKind->value) . '</label>'
                . '<input type="' . $inputType . '" id="' . $e($id) . '-' . $textKind->value . '" name="' . $e($ctx->name) . '[' . $textKind->value . ']" value="' . $e($value) . '"'
                . ($textKind === LinkKind::Url ? ' placeholder="' . $t('url_placeholder') . '" inputmode="url"' : '')
                . ' autocomplete="off">'
                . '</div>';
            $html .= $part(['field' => 'kind', '=' => $textKind->value], $input);
        }

        // The text the link shows, in the language being edited.
        $text = $link?->label($locale) ?? '';
        $html .= '  <div class="form-group"><label for="' . $e($id) . '-label">' . $t('text') . '</label><input type="text" id="' . $e($id) . '-label" name="' . $e($ctx->name) . '[label]" value="' . $e($text) . '"></div>' . "\n";
        $html .= '  <div class="form-group folio-link-tab"><label><input type="checkbox" name="' . $e($ctx->name) . '[new_tab]" value="1"' . ($link?->newTab === true ? ' checked' : '') . '> ' . $t('new_tab') . '</label></div>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /**
     * The record part: a type select when the field offers several types, and one relation picker
     * per type (each under {name}[record][{type}], shown for its type).
     *
     * @param list<string> $types
     * @param \Closure(array<mixed>, string): string $part
     */
    private function recordPicker(FieldContext $ctx, array $types, string $currentType, ?Link $link, string $id, \Closure $part): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $relation = $this->fieldTypes !== null && $this->fieldTypes->has('relation') ? $this->fieldTypes->get('relation') : null;
        if ($types === [] || !$relation instanceof RelationFieldType) {
            return '<p class="form-help">' . $e(FolioText::get('folio.fields.link.no_types')) . '</p>';
        }
        $html = '';
        if (count($types) > 1) {
            $html .= '<div class="form-group"><label for="' . $e($id) . '-type">' . $e(FolioText::get('folio.fields.link.record_type')) . '</label><select id="' . $e($id) . '-type" name="' . $e($ctx->name) . '[type]">';
            foreach ($types as $type) {
                $html .= '<option value="' . $e($type) . '"' . ($type === $currentType ? ' selected' : '') . '>' . $e($this->labelOf($type)) . '</option>';
            }
            $html .= '</select></div>';
        } else {
            $html .= '<input type="hidden" name="' . $e($ctx->name) . '[type]" value="' . $e($types[0]) . '">';
        }
        foreach ($types as $type) {
            $picker = $relation->renderEditor(new FieldContext(
                name: $ctx->name . '[record][' . $type . ']',
                label: $this->labelOf($type),
                value: $link !== null && $link->recordType() === $type ? $link->recordId() : '',
                config: ['relation' => ['to' => $type, 'multiple' => false]],
                locale: $ctx->locale,
                locales: $ctx->locales,
            ));
            $html .= count($types) > 1 ? trim($part(['all' => [['field' => 'kind', '=' => 'record'], ['field' => 'type', '=' => $type]]], $picker)) : $picker;
        }

        return $html;
    }

    /**
     * The file part: the asset field's single picker under {name}[asset] (its cards with the name
     * and thumbnail, Choose from the library, Upload), as the record part embeds the relation picker.
     */
    private function assetPicker(FieldContext $ctx, ?Link $link): string
    {
        $asset = $this->assetType();
        if ($asset === null) {
            return '';
        }

        return $asset->renderEditor(new FieldContext(
            name: $ctx->name . '[asset]',
            label: FolioText::get('folio.fields.link.asset'),
            value: $link !== null && $link->kind === LinkKind::Asset && is_string($link->target) ? $link->target : '',
            config: self::ASSET_CONFIG,
            locale: $ctx->locale,
            locales: $ctx->locales,
        ));
    }

    /** One file, public or private: what the asset field's picker takes for a link. */
    private const array ASSET_CONFIG = ['asset' => ['multiple' => false]];

    private function assetType(): ?AssetFieldType
    {
        $asset = $this->fieldTypes !== null && $this->fieldTypes->has('asset') ? $this->fieldTypes->get('asset') : null;

        return $asset instanceof AssetFieldType ? $asset : null;
    }

    /**
     * The submitted link: the chosen kind's target, the label merged into the stored one for the
     * language being edited (a text per language when the site has several), and new_tab. "No link"
     * (an empty kind) is null.
     *
     * @return array<string, mixed>|null
     */
    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx, array $config = []): mixed
    {
        $input = is_array($input) ? $input : [];
        $kind = is_string($input['kind'] ?? null) ? trim($input['kind']) : '';
        if ($kind === '') {
            return null;
        }
        if (LinkKind::tryFrom($kind) === LinkKind::Record) {
            $types = $this->offeredTypes(LinkOptions::fromConfig($config));
            $type = is_string($input['type'] ?? null) && $input['type'] !== '' ? $input['type'] : ($types[0] ?? '');
            $id = is_array($input['record'] ?? null) && is_string($input['record'][$type] ?? null) ? trim($input['record'][$type]) : '';
            $target = $id === '' ? ['type' => $type] : ['type' => $type, 'id' => $id];
        } elseif (LinkKind::tryFrom($kind) === LinkKind::Asset) {
            $target = $this->assetTarget($input, $existing, $files, $ctx);
        } else {
            $target = is_string($input[$kind] ?? null) ? trim($input[$kind]) : '';
        }

        $stored = Link::decode($existing);
        $label = $stored['label'] ?? null;
        $text = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        if ($ctx->locales->isMultilingual() || is_array($label)) {
            $map = is_array($label) ? array_filter($label, 'is_string') : (is_string($label) && $label !== '' ? [$ctx->locales->default => $label] : []);
            $map[$ctx->locale] = $text;
            if ($stored === null && $ctx->locale !== $ctx->locales->default) {
                // A link made while editing another language starts with the same text in the default one.
                $map[$ctx->locales->default] ??= $text;
            }
            $map = array_filter($map, static fn (string $t): bool => $t !== '');
            $label = $map === [] ? null : $map;
        } else {
            $label = $text === '' ? null : $text;
        }

        return ['kind' => $kind, 'target' => $target, 'label' => $label, 'new_tab' => ($input['new_tab'] ?? null) !== null && $input['new_tab'] !== '' && $input['new_tab'] !== '0'];
    }

    /**
     * The chosen file, through the asset field's resolve(): the posted selection ({name}[asset_ids])
     * or an upload ({name}[asset]); without a posted selection the stored file stays.
     *
     * @param array<mixed> $input
     * @param array<mixed> $files
     */
    private function assetTarget(array $input, mixed $existing, array $files, BuildContext $ctx): string
    {
        $asset = $this->assetType();
        if ($asset === null) {
            return '';
        }
        $stored = Link::fromStored($existing);
        $current = $stored !== null && $stored->kind === LinkKind::Asset && is_string($stored->target) ? $stored->target : '';
        $selection = $input['asset_ids'] ?? null;
        $uploads = array_values(array_filter(
            is_array($files['asset'] ?? null) ? $files['asset'] : [$files['asset'] ?? null],
            static fn (mixed $f): bool => $f instanceof \Psr\Http\Message\UploadedFileInterface && $f->getError() === UPLOAD_ERR_OK,
        ));
        $resolved = $asset->resolve(is_array($selection) ? array_values($selection) : null, $current, $uploads, self::ASSET_CONFIG, $ctx->writeUploads, null);

        return is_string($resolved) ? $resolved : (string) ($resolved[0] ?? '');
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->buildValue($raw, null, [], new BuildContext(Locales::single('en'), 'en', false), $config);
    }

    /** The stored object as it is (SQL storage writes JSON text of it); null for no link. */
    public function toStorage(mixed $value): mixed
    {
        return Link::decode($value);
    }

    /** The stored object (decoded from SQL storage's JSON text); null for no link. */
    public function fromStorage(mixed $value): mixed
    {
        return Link::decode($value);
    }

    /** An <a> with the link's href and label in the render's locale, its label alone without an href (a record that is gone), '' for no link. */
    public function renderFrontend(mixed $value, array $config): string
    {
        $link = Link::fromStored($value);
        if ($link === null) {
            return '';
        }
        $locale = \Preflow\Core\Context\Contexts::current()->locale;
        $attributes = $link->attributes($this->addresses !== null ? ($this->addresses)() : null, $locale);
        // The label along the content locales' fallback chain, as link_label() reads it.
        $text = $link->label($locale, $this->locales !== null ? ($this->locales)() : null) ?? ($attributes['href'] ?? '');
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($attributes === []) {
            return $text === '' ? '' : '<span class="folio-link-text">' . $e($text) . '</span>';
        }
        $html = '<a';
        foreach ($attributes as $name => $attribute) {
            $html .= ' ' . $name . '="' . $e($attribute) . '"';
        }

        return $html . '>' . $e($text) . '</a>';
    }

    /**
     * "About us → Page" for a list cell or a read-only field: the label (default language), what it
     * points to. No broken mark here (it would cost a lookup per row); the editor shows it.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $link = Link::fromStored($value);
        if ($link === null) {
            return '';
        }
        $target = $link->recordType() !== null ? $this->labelOf($link->recordType()) : (string) (is_string($link->target) ? $link->target : '');
        $label = is_array($link->label) ? (array_values($link->label)[0] ?? '') : (string) $link->label;
        $text = $label !== '' ? $label . ' → ' . $target : $target;

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** None of its own: the core's check (ValidLink, registered with FieldKinds::rules()) applies in Folio's validation too. */
    public function rules(array $config): array
    {
        return [];
    }

    /** A link to a file holds that asset's id (its target); other kinds hold none (since 0.91). */
    public function mapAssetIds(mixed $value, array $config, \Closure $fn): mixed
    {
        $json = is_string($value);
        $data = Link::decode($value);
        if ($data === null || ($data['kind'] ?? null) !== LinkKind::Asset->value || !is_string($data['target'] ?? null) || $data['target'] === '') {
            return $value;
        }
        $data['target'] = $fn($data['target']);

        return $json ? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data;
    }

    /**
     * show-when-core.js decides the kind's parts as conditional fields do; fields.js runs them, the
     * record picker and the file drop; admin.js (every editor loads it) the file picker's Choose and Remove.
     */
    public function assets(): array
    {
        return ['show-when-core.js', 'fields.js'];
    }

    /** The link text, as a translatable text "label" of the field (on a multilingual site). */
    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        $data = Link::decode($value);
        if (!$locales->isMultilingual() || $data === null || ($data['label'] ?? null) === null) {
            return [];
        }

        return [TranslatableText::of('label', FolioText::get('folio.fields.link.text'), $data['label'], $locales)];
    }

    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed
    {
        $data = Link::decode($value);
        if ($data === null || !isset($texts['label'])) {
            return $value;
        }
        $map = LocaleValue::map($data['label'] ?? null, $locales);
        $map[$locale] = $texts['label'];
        $data['label'] = $map;

        return $data;
    }

    /**
     * Whether the editor marks the link: a record or file link that a visitor's page renders without
     * an href. The same href decides (Link::href() through the bound RecordAddresses), read as a
     * visitor reads (published copies), so the mark and the page never disagree. Without
     * RecordAddresses nothing is marked (every page renders such links without an href alike).
     */
    private function isBroken(Link $link, string $locale): bool
    {
        if (!in_array($link->kind, [LinkKind::Record, LinkKind::Asset], true) || $this->addresses === null) {
            return false;
        }
        $addresses = ($this->addresses)();
        if ($addresses === null) {
            return false;
        }
        $current = \Preflow\Core\Context\Contexts::current();

        return \Preflow\Core\Context\Contexts::within($current->published(), static fn (): bool => $link->href($addresses, $locale) === null);
    }

    /** @return list<string> "link.types", else the linkable types (the routed ones) */
    private function offeredTypes(LinkOptions $options): array
    {
        return $options->types ?? ($this->linkableTypes !== null ? ($this->linkableTypes)() : []);
    }

    private function labelOf(string $type): string
    {
        return $this->typeLabel !== null ? ($this->typeLabel)($type) : ucfirst(str_replace('_', ' ', $type));
    }
}
