<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextFormat;
use Preflow\I18n\Machine\TextSource;
use Preflow\I18n\Machine\TranslationSettings;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\WriteOutcome;
use Preflow\I18n\Machine\WriteOutcomeStatus;
use Preflow\Validation\ValidationException;

/**
 * The records of one Folio type as a TextSource, so the core job runner can translate them.
 *
 * Units are the record's translatable texts (TranslationStatus::texts()), without the fields a
 * model opts out of with "ai": {"translate": false}. Writing goes the way of a manual save in the
 * target language: the texts are set (top-level fields as locale maps, structured fields through
 * AcceptsTranslatedTexts), stamped (TranslationStatus::stamp()) and marked as machine-made, then
 * saved. Publishing types get a working copy saved with the version check (a save by someone else
 * in between is a Conflict, never overwritten) and never a publish; a pending "Publish at" is
 * cancelled, as a save by someone who may not publish cancels it, so machine texts never go live
 * unreviewed. Types without publishing are saved directly (writesLive()).
 */
final class FolioTextSource implements TextSource
{
    public function __construct(
        private readonly string $typeKey,
        private readonly TypeCatalog $catalog,
        private readonly DataManager $dm,
        private readonly TranslationStatus $translations,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly ?Publisher $publisher,
        private readonly RecordLabeler $labeler,
        private readonly TranslationSettings $settings,
    ) {}

    public function collection(): string
    {
        return $this->typeKey;
    }

    public function label(): string
    {
        return $this->catalog->get($this->typeKey)?->label ?? $this->typeKey;
    }

    public function items(): array
    {
        return array_map(
            static fn (DynamicRecord $record): string => (string) $record->getId(),
            $this->dm->queryType($this->typeKey)->get()->items(),
        );
    }

    public function title(string $id): string
    {
        $record = $this->dm->findType($this->typeKey, $id);

        return $record === null ? $id : $this->labeler->label($record);
    }

    public function units(string $id, string $to, Scope $scope): array
    {
        $def = $this->definition();
        $record = $this->dm->findType($this->typeKey, $id);
        if ($record === null || !$this->translatesInto($def, $to)) {
            return [];
        }

        $missing = $outdated = [];
        if ($scope === Scope::Needed) {
            $missing = array_flip(self::paths($this->translations->missing($def, $record, $to)));
            $outdated = array_flip(self::paths($this->translations->outdated($def, $record, $to)));
        }

        $from = $def->locales->default;
        $context = ['type' => $this->label(), 'record' => $this->labeler->label($record)];
        $revisions = null; // read once, and only when an outdated text has no stored source
        $units = [];
        foreach ($this->texts($def, $record) as $text) {
            $source = $text->in($from);
            if ($source === null) {
                continue;
            }
            $previous = null;
            if ($scope === Scope::Needed) {
                if (isset($outdated[$text->path])) {
                    $previous = $this->translations->previousSource($record, $to, $text->path)
                        ?? $this->sourceFromRevisions($def, $record, $to, $text->path, $revisions);
                } elseif (!isset($missing[$text->path])) {
                    continue;
                }
            }
            $field = self::topField($text->path);
            $units[] = new TranslationUnit(
                id: $text->path,
                label: $text->label,
                source: $source,
                to: $to,
                from: $from,
                format: TextFormat::tryFrom($text->format) ?? TextFormat::Plain,
                kind: $text->kind,
                previousSource: $previous,
                current: $text->in($to),
                context: $context,
                tuning: $this->settings->unitTuning($this->typeKey, $field, $text->kind, $this->fieldAi($def, $field)),
                type: $this->typeKey,
            );
        }

        return $units;
    }

    public function write(string $id, string $to, array $texts): WriteOutcome
    {
        $def = $this->definition();
        if (!$this->translatesInto($def, $to)) {
            return new WriteOutcome(WriteOutcomeStatus::Skipped, "\"{$to}\" is not a language to translate into.");
        }
        $record = $this->dm->findType($this->typeKey, $id);
        if ($record === null) {
            return new WriteOutcome(WriteOutcomeStatus::Skipped, "Record \"{$id}\" not found.");
        }

        // Only texts with a default-language text can be translated: nothing is written for the others.
        $from = $def->locales->default;
        $known = array_flip(self::paths(array_filter(
            $this->texts($def, $record),
            static fn (TranslatableText $t): bool => $t->in($from) !== null,
        )));
        $data = $record->toArray();
        $structured = [];
        $expected = []; // path => the text that must be there after the write
        foreach ($texts as $path => $text) {
            $path = (string) $path;
            if (!isset($known[$path]) || !is_string($text) || trim($text) === '') {
                continue;
            }
            $field = self::topField($path);
            $config = $def->fields[$field]->config;
            $type = $this->fieldTypes->get($def->fields[$field]->type);
            if ($field === $path) {
                $map = LocaleValue::map($data[$field] ?? null, $def->locales);
                $map[$to] = $type->toStorage($type->normalizeInput($text, $config));
                $data[$field] = $map;
                $expected[$path] = (string) $map[$to];
            } elseif ($type instanceof AcceptsTranslatedTexts) {
                $structured[$field][substr($path, strlen($field) + 1)] = $text;
                $expected[$path] = $text;
            }
        }
        foreach ($structured as $field => $given) {
            $type = $this->fieldTypes->get($def->fields[$field]->type);
            \assert($type instanceof AcceptsTranslatedTexts);
            $data[$field] = $type->toStorage($type->withTranslatedTexts($type->fromStorage($data[$field] ?? null), $given, $to, $def->fields[$field]->config, $def->locales));
        }
        // A path counts as written only when its text is really there afterwards: a field type that
        // reports a path but cannot accept it would otherwise get a human translation marked as machine-made.
        $candidate = DynamicRecord::fromArray($def, $data);
        $now = [];
        foreach ($this->texts($def, $candidate) as $text) {
            $now[$text->path] = $text->in($to);
        }
        $written = [];
        foreach ($expected as $path => $text) {
            if (isset($now[$path]) && trim($now[$path]) === trim($text)) {
                $written[] = (string) $path;
            }
        }
        if ($written === []) {
            return new WriteOutcome(WriteOutcomeStatus::Skipped, 'No text to write.');
        }

        $after = $this->translations->stamp($def, $record, $candidate, $to);
        $after = $this->translations->markMachine($def, $after, $to, $written);

        if ($this->writesLive()) {
            $this->dm->saveType($after, false);

            return new WriteOutcome(WriteOutcomeStatus::Written);
        }

        \assert($this->publisher !== null);
        $hadDraftChanges = $this->publisher->state($record) === PublishState::Changed;
        if (($record->get('_publish_at') ?? '') !== '') {
            $after->set('_publish_at', '');
        }
        try {
            $this->publisher->saveDraft($after, (int) ($record->get('_version') ?? 0));
        } catch (VersionConflict $e) {
            return new WriteOutcome(WriteOutcomeStatus::Conflict, $e->getMessage(), $hadDraftChanges);
        } catch (ValidationException $e) {
            return new WriteOutcome(WriteOutcomeStatus::Skipped, self::firstError($e), $hadDraftChanges);
        }

        return new WriteOutcome(WriteOutcomeStatus::Written, null, $hadDraftChanges);
    }

    public function writesLive(): bool
    {
        return !($this->publisher?->enabled($this->typeKey) ?? false);
    }

    private function definition(): TypeDefinition
    {
        $registry = $this->dm->getTypeRegistry() ?? throw new \LogicException('FolioTextSource needs a TypeRegistry.');

        return $registry->get($this->typeKey);
    }

    private function translatesInto(TypeDefinition $def, string $to): bool
    {
        return $def->locales->isMultilingual() && $to !== $def->locales->default && $def->locales->has($to);
    }

    /**
     * The record's translatable texts the model lets machines translate.
     *
     * @return list<TranslatableText>
     */
    private function texts(TypeDefinition $def, DynamicRecord $record): array
    {
        return array_values(array_filter(
            $this->translations->texts($def, $record),
            fn (TranslatableText $t): bool => ($this->fieldAi($def, self::topField($t->path))['translate'] ?? true) !== false
                && $this->canBeWritten($def, self::topField($t->path)),
        ));
    }

    /**
     * Whether a translation of a field's texts can be stored: a field type that reports texts
     * (ReportsTranslatableTexts) but cannot take translated ones back (AcceptsTranslatedTexts)
     * is left out, so no paid call is made for a text that could never be written.
     */
    private function canBeWritten(TypeDefinition $def, string $field): bool
    {
        if (!isset($def->fields[$field])) {
            return true;
        }
        $type = $this->fieldTypes->get($def->fields[$field]->type);

        return !$type instanceof ReportsTranslatableTexts || $type instanceof AcceptsTranslatedTexts;
    }

    /**
     * The default text an outdated translation was made from, for records stamped before sources
     * were stored: the newest published revision whose default text at $path has the stamped
     * fingerprint. Null without publishing or when no revision matches.
     *
     * @param array<int, array<string, TranslatableText>>|null $revisions texts by path per revision, filled on first use
     */
    private function sourceFromRevisions(TypeDefinition $def, DynamicRecord $record, string $to, string $path, ?array &$revisions): ?string
    {
        $stamped = $this->translations->stampedFingerprint($record, $to, $path);
        if ($stamped === null || $this->writesLive()) {
            return null;
        }
        if ($revisions === null) {
            $revisions = [];
            foreach ($this->publisher?->revisions($this->typeKey, (string) $record->getId()) ?? [] as $revision) {
                $byPath = [];
                foreach ($this->translations->texts($def, DynamicRecord::fromArray($def, $revision->data)) as $text) {
                    $byPath[$text->path] = $text;
                }
                $revisions[] = $byPath;
            }
        }

        foreach ($revisions as $byPath) {
            $default = isset($byPath[$path]) ? $byPath[$path]->in($def->locales->default) : null;
            if ($default !== null && $this->translations->fingerprint($default) === $stamped) {
                return $default;
            }
        }

        return null;
    }

    /** @return array<string, mixed> the field's "ai" config in the model JSON */
    private function fieldAi(TypeDefinition $def, string $field): array
    {
        $ai = isset($def->fields[$field]) ? ($def->fields[$field]->config['ai'] ?? []) : [];

        return is_array($ai) ? $ai : [];
    }

    private static function topField(string $path): string
    {
        return explode('.', $path, 2)[0];
    }

    /**
     * @param list<TranslatableText> $texts
     * @return list<string>
     */
    private static function paths(array $texts): array
    {
        return array_map(static fn (TranslatableText $t): string => $t->path, $texts);
    }

    private static function firstError(ValidationException $e): string
    {
        foreach ($e->errors() as $field => $messages) {
            return $field . ': ' . (is_array($messages) ? (string) reset($messages) : (string) $messages);
        }

        return $e->getMessage();
    }
}
