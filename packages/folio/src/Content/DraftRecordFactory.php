<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\EnvFieldType;
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
 */
final class DraftRecordFactory
{
    /** @var array<string, list<string>> */
    private array $uploadErrors = [];

    private readonly TranslationStatus $translations;

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        ?TranslationStatus $translations = null,
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
        $assetType = $this->fieldTypes->has('asset') ? $this->fieldTypes->get('asset') : null;
        if ($assetType instanceof AssetFieldType) {
            $assetType->drainErrors(); // nothing left over from an earlier build
        }
        $uploads = $request->getUploadedFiles();
        $data = [];

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            if (self::locked($fieldDef->config)) {
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

        // System keys (e.g. _order) are not form fields; keep them.
        foreach ($existing as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_') && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        if ($translatable !== []) {
            $data['_locales'] = $this->locales($typeDef, $submitted, $existing);
        }

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
