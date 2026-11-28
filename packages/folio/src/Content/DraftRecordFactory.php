<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Placement;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\EnvFieldType;
use Preflow\Folio\Seo\SeoSection;
use Preflow\Validation\ValidationException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Builds a record from a submitted admin form: the one place shared by save
 * and live preview. A form edits one locale at a time; translatable values of
 * every other locale are kept from the stored record.
 *
 * Upload fields are always treated as shared across locales, even when marked
 * translatable (one file per record).
 *
 * Saving a translation stamps its fingerprints (TranslationStatus::stamp()), so
 * save, autosave and preview treat outdated translations alike. Machine marks come only
 * from translation jobs (the form posts none); a mark stays while its text is unchanged.
 *
 * Hidden and read-only fields ("hidden": true, "readonly": true) are never taken from
 * the form: the stored value is kept (a new record starts without one, unless a
 * BeforeSave hook sets it).
 *
 * A field its show_when hides (0.91) keeps its stored value too, whatever was posted for it
 * (FieldVisibility::keepStored(); the editor disables its input, but a post can carry one). Which
 * fields are hidden is decided from the posted source values before any upload is stored, so a
 * file posted for a hidden field is never written.
 *
 * A type with page layouts (0.92) posts its layout section with the marker "_areas_submitted":
 * "_layout" (the chosen layout; a pinned page posts none: its stored key stays, the pin decides)
 * and "_areas[<area>]", each area as a blocks field posts its blocks (BlocksFieldType::buildValue(),
 * an "_order" without blocks empties it; an empty area the page does not store is no write). The built areas and the layout go to the core's LayoutInput::merge(), as an API's write
 * does: areas the form did not post keep their blocks, an area neither the layout has nor the
 * page stores is refused. Without the marker the stored layout and areas stay as they are. What
 * the core refused is in buildErrors(), keyed as the core keys it ("_areas.<area>…"); the record
 * then holds the layout and areas as posted (over the stored ones) for the form to show again, and
 * must not be saved: every save path checks buildErrors() first.
 */
final class DraftRecordFactory
{
    /** @var array<string, list<string>> */
    private array $uploadErrors = [];

    /** @var array<string, list<string>> */
    private array $layoutErrors = [];

    private readonly TranslationStatus $translations;

    /**
     * @param PageLayouts|null $layouts     the types' page layouts (0.92); without, no type has any
     * @param LayoutInput|null $layoutInput the core's merge of a posted layout and areas (bound with $layouts)
     */
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        ?TranslationStatus $translations = null,
        private readonly ?PageLayouts $layouts = null,
        private readonly ?LayoutInput $layoutInput = null,
    ) {
        $this->translations = $translations ?? new TranslationStatus($fieldTypes);
    }

    /**
     * Uploads the last build() could not store, keyed by field (form errors).
     *
     * @return array<string, list<string>>
     */
    public function uploadErrors(): array
    {
        return $this->uploadErrors;
    }

    /**
     * What the last build() could not take, keyed as form errors: the uploads it could not store
     * (uploadErrors()) and, for a type with page layouts, the posted layout and areas the core
     * refused (LayoutInput::merge()).
     *
     * @return array<string, list<string>>
     * @since 0.92.0
     */
    public function buildErrors(): array
    {
        return $this->uploadErrors + $this->layoutErrors;
    }

    /**
     * @param array<string, mixed> $existing     the stored record (empty for a new one)
     * @param bool                 $writeUploads false for previews: no files are written
     */
    public function build(
        TypeDefinition $typeDef,
        ServerRequestInterface $request,
        array $existing,
        string $locale,
        bool $writeUploads = true,
    ): DynamicRecord {
        $locales = $typeDef->locales;
        $locale = $locales->has($locale) ? $locale : $locales->default;
        $translatable = array_flip($typeDef->translatableFields());
        $submitted = (array) $request->getParsedBody();
        $this->uploadErrors = [];
        $this->layoutErrors = [];
        $assetType = $this->fieldTypes->has('asset') ? $this->fieldTypes->get('asset') : null;
        if ($assetType instanceof AssetFieldType) {
            $assetType->drainErrors(); // nothing left over from an earlier build
        }
        $uploads = $request->getUploadedFiles();
        $data = [];
        $hidden = array_flip(FieldVisibility::hiddenFieldsOf($typeDef, $this->sourceValues($typeDef, $submitted, $existing)));

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            if (self::locked($fieldDef->config) || isset($hidden[$name])) {
                if (array_key_exists($name, $existing)) {
                    $data[$name] = $existing[$name];
                }
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if ($fieldType instanceof EnvFieldType) {
                continue; // names an environment variable: nothing is taken or stored, not even the key
            }

            if ($fieldType instanceof StructuredFieldType) {
                $data[$name] = $fieldType->buildValue(
                    $submitted[$name] ?? [],
                    $existing[$name] ?? null,
                    is_array($uploads[$name] ?? null) ? $uploads[$name] : [],
                    new BuildContext($locales, $locale, $writeUploads),
                    $fieldDef->config,
                );
                if ($assetType instanceof AssetFieldType && ($errors = $assetType->drainErrors()) !== []) {
                    $this->uploadErrors[$name] = $errors; // uploads inside blocks
                }
                continue;
            }

            if ($fieldType instanceof AssetFieldType) {
                $selection = $submitted[AssetFieldType::selectionName($name)] ?? null;
                $data[$name] = $fieldType->resolve(
                    is_array($selection) ? array_values($selection) : null,
                    LocaleValue::default($existing[$name] ?? null, $locales),
                    self::uploadedFiles($uploads[$name] ?? null),
                    $fieldDef->config,
                    $writeUploads,
                    null,
                );
                if (($errors = $fieldType->drainErrors()) !== []) {
                    $this->uploadErrors[$name] = $errors;
                }
                continue;
            }

            if ($fieldType instanceof HandlesUpload) {
                $files = $writeUploads ? self::uploadedFiles($uploads[$name] ?? null) : [];
                $existingList = self::pathList($fieldType->fromStorage(LocaleValue::default($existing[$name] ?? null, $locales)));
                $data[$name] = $fieldType->toStorage($fieldType->storeUploads($files, $existingList, $fieldDef->config));
                continue;
            }

            $value = $fieldType->toStorage($fieldType->normalizeInput($submitted[$name] ?? null, $fieldDef->config));

            if (isset($translatable[$name])) {
                $map = LocaleValue::map($existing[$name] ?? null, $locales);
                $map[$locale] = $value;
                $data[$name] = $map;
                continue;
            }

            $data[$name] = $value;
        }

        if (isset($submitted['_seo_submitted'])) {
            $data[SeoSection::KEY] = $this->seo($typeDef, $submitted, $existing, $uploads, $locale, $translatable !== [], $writeUploads);
        }

        // System keys (e.g. _order) are not form fields; keep them.
        foreach ($existing as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_') && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        if ($translatable !== []) {
            $data['_locales'] = $this->locales($typeDef, $submitted, $existing);
        }

        if (isset($submitted['_areas_submitted'])) {
            $data = $this->layout($typeDef, $submitted, $data, is_array($uploads[PageLayouts::AREAS] ?? null) ? $uploads[PageLayouts::AREAS] : [], new BuildContext($locales, $locale, $writeUploads), $assetType);
        }

        $data = FieldVisibility::keepStored($typeDef, $data, $existing);
        $data[$typeDef->idField] = $existing[$typeDef->idField] ?? null;

        // A translation saved here records the default text it was made from (outdated marks).
        return $this->translations->stamp(
            $typeDef,
            DynamicRecord::fromArray($typeDef, $existing),
            DynamicRecord::fromArray($typeDef, $data),
            $locale,
        );
    }

    /**
     * The values with the posted layout section (_layout, _areas[<area>]) merged in by the core
     * (LayoutInput::merge()); as they were for a type without page layouts, or with the core's
     * refusal in buildErrors().
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $data     the values built so far, the stored system keys among them
     * @param array<string, mixed> $uploads  the files posted under _areas
     * @return array<string, mixed>
     */
    private function layout(TypeDefinition $typeDef, array $submitted, array $data, array $uploads, BuildContext $ctx, ?FieldType $assetType): array
    {
        $blocks = $this->fieldTypes->has('blocks') ? $this->fieldTypes->get('blocks') : null;
        if ($this->layouts?->forType($typeDef->key) === null || $this->layoutInput === null || !$blocks instanceof BlocksFieldType) {
            return $data;
        }

        $input = [];
        if (array_key_exists(PageLayouts::LAYOUT, $submitted)) {
            // "" (nothing chosen) is the type's default, as null is for the core.
            $input[PageLayouts::LAYOUT] = $submitted[PageLayouts::LAYOUT] === '' ? null : $submitted[PageLayouts::LAYOUT];
        }
        $stored = Placement::areasOf($data[PageLayouts::AREAS] ?? null);
        $posted = is_array($submitted[PageLayouts::AREAS] ?? null) ? $submitted[PageLayouts::AREAS] : [];
        $areas = [];
        foreach ($posted as $name => $post) {
            // Each area is built as a blocks field: whether it takes a block is the core's to say (AreaContents).
            $built = $blocks->buildValue(
                is_array($post) ? $post : [],
                $stored[(string) $name] ?? [],
                is_array($uploads[$name] ?? null) ? $uploads[$name] : [],
                $ctx,
            );
            // The form posts every area it shows, empty ones too; one the page does not store and that
            // holds nothing is no write (an area a stored page lacks counts as empty).
            if ($built !== [] || array_key_exists((string) $name, $stored)) {
                $areas[(string) $name] = $built;
            }
        }
        if ($assetType instanceof AssetFieldType && ($errors = $assetType->drainErrors()) !== []) {
            $this->uploadErrors[PageLayouts::AREAS] = $errors; // uploads inside area blocks
        }
        if ($areas !== []) {
            $input[PageLayouts::AREAS] = $areas;
        }

        try {
            // The form edits block fields declared hidden (no API writes them): taken, not refused.
            return $this->layoutInput->merge($typeDef, $data, $input, hiddenFields: true);
        } catch (ValidationException $e) {
            $this->layoutErrors = $e->errors();

            // Refused: nothing of it may be saved (every save checks buildErrors()), but the form shows
            // the layout and areas as posted, over the stored ones, so the editor's edits stay in it.
            if (array_key_exists(PageLayouts::LAYOUT, $input) && (is_string($input[PageLayouts::LAYOUT]) || $input[PageLayouts::LAYOUT] === null)) {
                $data[PageLayouts::LAYOUT] = $input[PageLayouts::LAYOUT];
            }
            $data[PageLayouts::AREAS] = array_replace($stored, $areas);

            return $data;
        }
    }

    /**
     * "Visible in" checkboxes (_locales[] with a _locales_submitted marker), or
     * the stored list; the default locale is always included.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $existing
     * @return list<string>
     */
    private function locales(TypeDefinition $typeDef, array $submitted, array $existing): array
    {
        $chosen = isset($submitted['_locales_submitted'])
            ? (array) ($submitted['_locales'] ?? [])
            : ($existing['_locales'] ?? []); // as stored (SQL drivers: JSON text); availableLocales() decodes

        return DynamicRecord::fromArray($typeDef, ['_locales' => $chosen])->availableLocales();
    }

    /**
     * The "SEO & sharing" section (_seo[...] with a _seo_submitted marker): its share image
     * resolved like an asset field's selection, its texts for the edited locale (the default
     * one for a type without translations). All defaults store nothing, so saving an
     * untouched section leaves a published record published.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $uploads
     * @return array<string, mixed>|null
     */
    private function seo(TypeDefinition $typeDef, array $submitted, array $existing, array $uploads, string $locale, bool $translatable, bool $writeUploads): ?array
    {
        $posted = is_array($submitted[SeoSection::KEY] ?? null) ? $submitted[SeoSection::KEY] : [];
        $stored = SeoSection::fromValue($existing[SeoSection::KEY] ?? null);
        $assetType = $this->fieldTypes->has('asset') ? $this->fieldTypes->get('asset') : null;
        $image = $stored->image();
        if ($assetType instanceof AssetFieldType) {
            $selection = $posted['image_ids'] ?? null;
            $image = $assetType->resolve(
                is_array($selection) ? array_values($selection) : null,
                $image,
                self::uploadedFiles(is_array($uploads[SeoSection::KEY] ?? null) ? ($uploads[SeoSection::KEY]['image'] ?? null) : null),
                SeoSection::IMAGE_CONFIG,
                $writeUploads,
                null,
            );
            if (($errors = $assetType->drainErrors()) !== []) {
                $this->uploadErrors[SeoSection::KEY] = $errors;
            }
        }

        $value = SeoSection::fromSubmission(
            ['image' => $image] + $posted,
            $stored->toArray(),
            $translatable ? $locale : $typeDef->locales->default,
            $translatable,
        );

        return SeoSection::fromValue($value)->isEmpty() ? null : $value;
    }

    /**
     * The stored values with each show_when source as posted (as the loop below takes it), to decide
     * which fields are hidden before anything is built or uploaded. A source the form does not post
     * as a plain value (locked, structured, asset, upload) counts with its stored value. The stored
     * values alone for a type without show_when.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function sourceValues(TypeDefinition $typeDef, array $submitted, array $existing): array
    {
        foreach (FieldVisibility::sourcesOf($typeDef) as $source) {
            $fieldDef = $typeDef->fields[$source] ?? null;
            if ($fieldDef === null || self::locked($fieldDef->config)) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if ($fieldType instanceof StructuredFieldType || $fieldType instanceof AssetFieldType || $fieldType instanceof HandlesUpload || $fieldType instanceof EnvFieldType) {
                continue;
            }
            $existing[$source] = $fieldType->toStorage($fieldType->normalizeInput($submitted[$source] ?? null, $fieldDef->config));
        }

        return $existing;
    }

    /**
     * A field the admin form never sets: "hidden" or "readonly" in its config.
     *
     * @param array<string, mixed> $config
     */
    private static function locked(array $config): bool
    {
        return ($config['hidden'] ?? false) === true || ($config['readonly'] ?? false) === true;
    }

    /** @return UploadedFileInterface[] */
    private static function uploadedFiles(mixed $entry): array
    {
        if ($entry instanceof UploadedFileInterface) {
            return [$entry];
        }
        if (is_array($entry)) {
            return array_values(array_filter($entry, static fn ($f) => $f instanceof UploadedFileInterface));
        }
        return [];
    }

    /** @return string[] */
    private static function pathList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
    }
}
