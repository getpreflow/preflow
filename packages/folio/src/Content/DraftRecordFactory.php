<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Builds a record from a submitted admin form: the one place shared by save
 * and live preview. A form edits one locale at a time; translatable values of
 * every other locale are kept from the stored record.
 *
 * Upload fields are always treated as shared across locales, even when marked
 * translatable (one file per record).
 */
final class DraftRecordFactory
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
    ) {}

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
        $uploads = $request->getUploadedFiles();
        $data = [];

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);

            if ($fieldType instanceof HandlesUpload) {
                $files = $writeUploads ? self::uploadedFiles($uploads[$name] ?? null) : [];
                $removed = array_values(array_filter(
                    (array) ($submitted[$name . '_remove'] ?? []),
                    static fn ($v) => is_string($v),
                ));
                $existingList = self::pathList($fieldType->fromStorage(LocaleValue::default($existing[$name] ?? null, $locales)));
                $kept = array_values(array_diff($existingList, $removed));
                $data[$name] = $fieldType->toStorage($fieldType->storeUploads($files, $kept, $fieldDef->config));
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

        return DynamicRecord::fromArray($typeDef, $data);
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
            : (array) ($existing['_locales'] ?? []);

        return DynamicRecord::fromArray($typeDef, ['_locales' => $chosen])->availableLocales();
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
