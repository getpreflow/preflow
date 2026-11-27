<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\Field\SearchableText;
use Preflow\Folio\Field\StoresList;
use Preflow\Folio\FolioText;

/**
 * Tag field (0.77): a list of short texts ("Folio", "members"), a list in a JSON file (0.78) and a
 * JSON list in a SQL text column (JSON_UNESCAPED_UNICODE, so a loose search finds "Übersetzung"
 * with the ordinary CONTAINS on every storage). Stored JSON text and a legacy comma string
 * ("Folio, members") are read as the list.
 *
 * Options: "max" (tags per record, default 20), "maxLength" (characters per tag, default 40) and
 * "options" (a list of texts the add input suggests). A translatable tags field is a boot error:
 * one list serves every language.
 *
 * The editor: chips with ✕ and an inline "Add…" input (fields.js; Enter and comma add, Backspace on
 * the empty input removes the last, a pasted "a, b, c" adds three). Without script it is one
 * comma-separated text input under the field's own name.
 */
final class TagsFieldType implements FieldType, HasColumnKind, SearchableText, DisplaysInAdmin, StoresList, \Preflow\Folio\Field\UsesFieldKinds
{
    use \Preflow\Folio\Field\Types\CoreValue;

    public const int DEFAULT_MAX = 20;

    public const int DEFAULT_MAX_LENGTH = 40;

    /** Tags a list cell shows before "+N". */
    private const int ADMIN_TAGS = 3;

    public function key(): string
    {
        return 'tags';
    }

    /** The core value of this type (used without kinds, or when they hold none for the key). */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new \Preflow\Data\Values\Core\TagsValue();
    }

    public function columnKind(array $config): ColumnKind
    {
        return ColumnKind::Text;
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tags = $this->fromStorage($ctx->value);
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = $ctx->name;
        $listName = $ctx->name . '[]';
        $options = self::suggestions($ctx->config);
        $optionsId = $id . '-tag-options';
        $hasError = $ctx->errors !== [];

        $html = '<div class="form-group folio-tags-field' . ($hasError ? ' has-error' : '') . '" data-folio-tags'
            . ' data-folio-tags-name="' . $e($listName) . '"'
            . ' data-max="' . self::limit($ctx->config, 'max', self::DEFAULT_MAX) . '"'
            . ' data-max-length="' . self::limit($ctx->config, 'maxLength', self::DEFAULT_MAX_LENGTH) . '"'
            . ' data-text-remove="' . $e(FolioText::get('folio.fields.tag_remove')) . '"'
            . ' data-text-added="' . $e(FolioText::get('folio.fields.tag_added')) . '"'
            . ' data-text-removed="' . $e(FolioText::get('folio.fields.tag_removed')) . '"'
            . ' data-text-too-many="' . $e(FolioText::get('folio.fields.tags_too_many')) . '"'
            . ' data-text-too-long="' . $e(FolioText::get('folio.fields.tags_too_long')) . '">' . "\n";
        $html .= '  <label for="' . $e($id) . '">' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</label>' . "\n";

        // With script: the chips and the add input. A chip's hidden input gets its name (name[]) from
        // fields.js, which then turns the plain input off: without script (or when fields.js never
        // runs) only the plain input posts, so a save never loses the tags.
        $html .= '  <div class="folio-tags-box">';
        $html .= '<ul class="folio-tags-list" data-folio-tags-list>';
        foreach ($tags as $tag) {
            $html .= $this->chip($tag);
        }
        $html .= '</ul>';
        $html .= '<input type="text" id="' . $e($id) . '-tags-add" class="folio-tags-input" data-folio-tags-input data-folio-no-edit autocomplete="off"'
            . ' placeholder="' . $e(FolioText::get('folio.fields.tags_add')) . '"'
            . ' aria-label="' . $e(FolioText::get('folio.fields.tags_add_label', ['label' => $label])) . '"'
            . ' aria-describedby="' . $e($id) . '-tags-hint"'
            . ($options !== [] ? ' list="' . $e($optionsId) . '"' : '') . '>';
        $html .= '<template data-folio-tags-template>' . $this->chip('') . '</template>';
        $html .= '</div>' . "\n";
        if ($options !== []) {
            $html .= '  <datalist id="' . $e($optionsId) . '">'
                . implode('', array_map(static fn (string $o): string => '<option value="' . $e($o) . '"></option>', $options))
                . '</datalist>' . "\n";
        }

        // Without script: one text input, "Folio, members".
        $html .= '  <input type="text" id="' . $e($id) . '" name="' . $e($ctx->name) . '" value="' . $e(implode(', ', $tags)) . '"'
            . ' class="folio-tags-plain" data-folio-tags-plain aria-describedby="' . $e($id) . '-tags-hint"'
            . ($ctx->placeholder !== null ? ' placeholder="' . $e($ctx->placeholder) . '"' : '') . '>' . "\n";

        $html .= '  <small class="form-help folio-tags-hint" id="' . $e($id) . '-tags-hint">'
            . '<span class="folio-tags-hint-js">' . $e(FolioText::get('folio.fields.tags_hint')) . '</span>'
            . '<span class="folio-tags-hint-plain">' . $e(FolioText::get('folio.fields.tags_hint_plain')) . '</span>'
            . ($ctx->help !== null && $ctx->help !== '' ? ' ' . $e($ctx->help) : '')
            . '</small>' . "\n";
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }

        return $html . '</div>';
    }

    /** One chip; with an empty tag, the template fields.js fills. */
    private function chip(string $tag): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $remove = FolioText::get('folio.fields.tag_remove', ['tag' => $tag]);

        return '<li class="folio-tag-chip" data-folio-tag>'
            . '<span class="folio-tag-text">' . $e($tag) . '</span>'
            . '<button type="button" class="folio-tag-remove" data-folio-tag-remove aria-label="' . $e($remove) . '" title="' . $e($remove) . '">'
            . \Preflow\Folio\View\InlineIcon::svg('ui-x') . '</button>'
            . '<input type="hidden" value="' . $e($tag) . '" data-folio-tag-value></li>';
    }

    /**
     * A posted list (name[]), its JSON text or a comma string (the input without script) as the
     * core stores tags (TagsValue): trimmed, without control characters and empty ones, in NFC,
     * each once (case ignored, the first spelling kept). A tag cannot hold a comma: the comma
     * separates tags. Nothing is cut: a list over the limits stays so, and the field's rule
     * refuses it. No tags is [] here (toStorage() stores it as null).
     *
     * @return list<string>
     */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        $tags = $this->stored($raw, $config);

        return is_array($tags) ? $tags : [];
    }

    /**
     * The list (0.78), as the core stores it (TagsValue): a JSON file holds it as a list, SQL
     * storage as JSON text in its column (umlauts readable, for search). No tags is null (no
     * value), so "required" refuses an empty field.
     *
     * @return list<string>|null
     */
    public function toStorage(mixed $value): mixed
    {
        return $this->stored($value);
    }

    /**
     * The stored JSON list (a list where the storage or a class model holds one), or a legacy
     * comma string split into its parts, as the core reads it out (TagsValue).
     *
     * @return list<string>
     */
    public function fromStorage(mixed $value): mixed
    {
        $tags = $this->output($value);

        return is_array($tags) ? $tags : [];
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $tags = $this->fromStorage($value);
        if ($tags === []) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<ul class="tags">' . implode('', array_map(static fn (string $t): string => '<li>' . $e($t) . '</li>', $tags)) . '</ul>';
    }

    /**
     * A list cell or read-only field (plain text there): the first tags, comma-separated, and "+N"
     * for the rest ("Folio, members, AI +2").
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $tags = $this->fromStorage($value);
        $more = count($tags) - self::ADMIN_TAGS;
        $text = implode(', ', array_slice($tags, 0, self::ADMIN_TAGS)) . ($more > 0 ? ' +' . $more : '');

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return [new TagLimits(
            self::limit($config, 'max', self::DEFAULT_MAX),
            self::limit($config, 'maxLength', self::DEFAULT_MAX_LENGTH),
            $this,
        )];
    }

    public function assets(): array
    {
        return ['fields.js'];
    }

    /**
     * The field's options, checked at boot (TypeCatalog): "translatable" is refused, "max" and
     * "maxLength" are whole numbers of 1 or more, "options" a list of texts.
     *
     * @param array<string, mixed> $config the field's entry of the model file
     * @throws \LogicException
     */
    public static function checkConfig(array $config): void
    {
        // The same truthiness as TypeRegistry's, which makes the field translatable.
        if ((bool) ($config['translatable'] ?? false)) {
            throw new \LogicException('A tags field cannot be translatable; keep one list for every language.');
        }
        foreach (['max', 'maxLength'] as $option) {
            if (array_key_exists($option, $config) && (!is_int($config[$option]) || $config[$option] < 1)) {
                throw new \LogicException('Tags field option "' . $option . '" must be a whole number of 1 or more.');
            }
        }
        if (array_key_exists('options', $config)) {
            $options = $config['options'];
            if (!is_array($options) || !array_is_list($options) || array_filter($options, static fn ($o): bool => !is_string($o) || trim($o) === '') !== []) {
                throw new \LogicException('Tags field option "options" must be a list of texts.');
            }
        }
    }

    /** @param array<string, mixed> $config */
    private static function limit(array $config, string $option, int $default): int
    {
        $value = $config[$option] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function suggestions(array $config): array
    {
        $options = $config['options'] ?? [];

        return is_array($options) ? array_values(array_filter($options, static fn ($o): bool => is_string($o) && trim($o) !== '')) : [];
    }
}
