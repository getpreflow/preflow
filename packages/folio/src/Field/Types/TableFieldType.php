<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Fields\TableOptions;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Table;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\FolioText;

/**
 * A table of plain-text cells with an optional header row (0.91; the value, check and storage are
 * preflow/fields': Values\Table, TableOptions, ValidTable). The editor is a grid of text inputs:
 * the header row first, then the rows.
 *
 * Rows and columns: in the default language (or on a single-language site, or for a table not
 * saved yet) the grid has one empty row and one empty column more, while the field's
 * "max_rows" / "max_columns" allow: typing into them adds a row or a column (a new table starts
 * two by two). Emptying every cell of a row (or a column) there removes it on save, with its
 * translations; emptying one cell there empties its translations too. In another language the grid
 * keeps the default language's rows and columns; each input shows the default text as its
 * placeholder and saves the translation of that cell only. No script is needed.
 *
 * Posted: {name}[head][c], {name}[rows][r][c]. Stored: Table::toStored()'s object, each cell a
 * text, or a text per language on a multilingual site (as a link's label). The cells count in the
 * translation status and go to machine translation as "head.c" / "rows.r.c". The check is the
 * core's: rules() adds none.
 */
final class TableFieldType implements FieldType, StructuredFieldType, DisplaysInAdmin, ReportsTranslatableTexts, AcceptsTranslatedTexts
{
    /** @param (\Closure(): ?Locales)|null $locales the content locales, for a cell's fallback on the frontend */
    public function __construct(
        private readonly ?\Closure $locales = null,
    ) {}

    public function key(): string
    {
        return 'table';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = static fn (string $key, array $params = []): string => $e(FolioText::get('folio.fields.table.' . $key, $params));
        $options = TableOptions::fromConfig($ctx->config);
        $locales = $ctx->locales ?? Locales::single($ctx->locale ?? 'en');
        $locale = $ctx->locale ?? $locales->default;
        $grid = self::grid($ctx->value);
        $structure = self::editsStructure($locale, $locales, $grid);
        $columns = $grid['columns'];
        $rows = count($grid['rows']);
        if ($grid['columns'] === 0) {
            // A new table starts as two columns and two rows (fewer when the limits say so).
            $columns = min(2, $options->maxColumns ?? 2);
            $rows = min(2, $options->maxRows ?? 2);
        } elseif ($structure) {
            // One empty column and row more, to type into, while the limits allow.
            $columns += $options->maxColumns === null || $columns < $options->maxColumns ? 1 : 0;
            $rows += $options->maxRows === null || $rows < $options->maxRows ? 1 : 0;
        }
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $hasError = $ctx->errors !== [];
        $name = $e($ctx->name);
        $source = $locale !== $locales->default;

        // A cell's input: its text in the language being edited; in another language the default
        // text is the placeholder (what to translate).
        $input = static function (string $field, string $aria, string|array|null $cell) use ($e, $locale, $locales, $source): string {
            if ($source) {
                $value = is_array($cell) ? ($cell[$locale] ?? '') : '';
            } else {
                $value = is_array($cell) ? ($cell[$locale] ?? '') : ($cell ?? '');
            }
            $placeholder = $source && $cell !== null ? Table::cellText($cell, $locales->default) : '';

            return '<input type="text" name="' . $field . '" value="' . $e($value) . '" aria-label="' . $e($aria) . '"'
                . ($placeholder !== '' && $placeholder !== $value ? ' placeholder="' . $e($placeholder) . '"' : '') . '>';
        };

        $html = '<fieldset class="form-group folio-table' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        $html .= '  <div class="folio-table-scroll"><table class="folio-table-grid"><tbody>' . "\n";
        $html .= '    <tr class="folio-table-head"><th scope="row">' . $t('header') . '</th>';
        for ($c = 0; $c < $columns; $c++) {
            $html .= '<td>' . $input($name . '[head][' . $c . ']', FolioText::get('folio.fields.table.header_cell', ['column' => (string) ($c + 1)]), $grid['head'][$c] ?? null) . '</td>';
        }
        $html .= "</tr>\n";
        for ($r = 0; $r < $rows; $r++) {
            $html .= '    <tr><th scope="row">' . ($r + 1) . '</th>';
            for ($c = 0; $c < $columns; $c++) {
                $html .= '<td>' . $input($name . '[rows][' . $r . '][' . $c . ']', FolioText::get('folio.fields.table.cell', ['row' => (string) ($r + 1), 'column' => (string) ($c + 1)]), $grid['rows'][$r][$c] ?? null) . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html .= "  </tbody></table></div>\n";
        $html .= '  <small class="form-help">' . ($structure ? $t('hint') : $t('hint_translate', ['language' => strtoupper($locales->default)])) . '</small>' . "\n";
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /**
     * The submitted grid merged into the stored table: each posted cell's text set for the language
     * being edited (a text per language on a multilingual site). In the default language (or a new
     * table) the posted grid decides the rows and columns, and a row or column without a text in the
     * default language is dropped with its translations; in another language the stored rows and
     * columns stay. No text at all: null.
     *
     * @return array{head: list<string|array<string, string>>|null, rows: list<list<string|array<string, string>>>}|null
     */
    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx, array $config = []): mixed
    {
        $input = is_array($input) ? $input : [];
        $stored = self::grid($existing);
        $multilingual = $ctx->locales->isMultilingual();
        $structure = self::editsStructure($ctx->locale, $ctx->locales, $stored);
        $posted = static fn (mixed $value): ?string => is_string($value) ? trim($value) : null;
        $merge = static function (string|array|null $old, ?string $text) use ($ctx, $multilingual, $stored): string|array {
            if ($text === null) {
                return $old ?? '';
            }
            if (!$multilingual && !is_array($old)) {
                return $text;
            }
            if ($text === '' && $ctx->locale === $ctx->locales->default) {
                return ''; // the default text emptied: its translations have nothing left to translate
            }
            $map = is_array($old) ? $old : (is_string($old) && $old !== '' ? [$ctx->locales->default => $old] : []);
            $map[$ctx->locale] = $text;
            if ($stored['columns'] === 0 && $ctx->locale !== $ctx->locales->default) {
                $map[$ctx->locales->default] ??= $text; // a table made in another language starts with the same text in the default one
            }

            return array_filter($map, static fn (string $t): bool => $t !== '');
        };

        $postedHead = is_array($input['head'] ?? null) ? $input['head'] : [];
        $postedRows = is_array($input['rows'] ?? null) ? array_values(array_filter($input['rows'], 'is_array')) : [];
        if ($structure) {
            $columns = max(count($postedHead), ...array_map('count', $postedRows ?: [[]]));
            $rowCount = count($postedRows);
        } else {
            $columns = $stored['columns'];
            $rowCount = count($stored['rows']);
        }
        $head = [];
        for ($c = 0; $c < $columns; $c++) {
            $head[] = $merge($stored['head'][$c] ?? null, $posted($postedHead[$c] ?? null));
        }
        $rows = [];
        for ($r = 0; $r < $rowCount; $r++) {
            $row = [];
            for ($c = 0; $c < $columns; $c++) {
                $row[] = $merge($stored['rows'][$r][$c] ?? null, $posted($postedRows[$r][$c] ?? null));
            }
            $rows[] = $row;
        }
        $cleaned = array_map(self::emptyAsText(...), [$head, ...$rows]);
        $head = array_shift($cleaned);
        $rows = $cleaned;
        if ($structure) {
            // Drop the rows, then the columns, without a text in the default language (their
            // translations go with them: there is nothing left to translate).
            $source = static fn (string|array $cell): bool => trim(Table::cellText($cell, $ctx->locales->default)) !== '';
            $rows = array_values(array_filter($rows, static fn (array $row): bool => array_filter($row, $source) !== []));
            $keep = [];
            for ($c = 0; $c < $columns; $c++) {
                foreach ([$head, ...$rows] as $row) {
                    if ($source($row[$c])) {
                        $keep[] = $c;
                        break;
                    }
                }
            }
            $pick = static fn (array $row): array => array_map(static fn (int $c): string|array => $row[$c], $keep);
            $head = $pick($head);
            $rows = array_map($pick, $rows);
        }
        $value = ['head' => array_filter($head, static fn (string|array $c): bool => !Table::isEmpty($c)) === [] ? null : $head, 'rows' => $rows];

        return Table::fromStored($value) === null ? null : $value;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->buildValue($raw, null, [], new BuildContext(Locales::single('en'), 'en', false), $config);
    }

    /** The stored object (padded by Table); null for no table. Something that is no table stays as it is, for the check to refuse. */
    public function toStorage(mixed $value): mixed
    {
        $table = Table::fromStored($value);

        return $table !== null ? $table->toStored() : (is_array($value) && $value !== [] ? $value : null);
    }

    public function fromStorage(mixed $value): mixed
    {
        return Table::fromStored($value)?->toStored();
    }

    /** The core's table HTML (escaped) in the render's locale, along the content locales' fallback chain. */
    public function renderFrontend(mixed $value, array $config): string
    {
        return Table::fromStored($value)?->html(\Preflow\Core\Context\Contexts::current()->locale, $this->locales !== null ? ($this->locales)() : null) ?? '';
    }

    /**
     * "3 rows × 2 columns" for a list cell or a read-only field.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $table = Table::fromStored($value);
        if ($table === null) {
            return '';
        }

        return htmlspecialchars(FolioText::get('folio.fields.table.size', ['rows' => (string) count($table->rows), 'columns' => (string) $table->columns()]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** None of its own: the core's check (ValidTable, through FieldKinds::rules()) applies in Folio too. */
    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }

    /** Each cell with a text, as "head.{c}" and "rows.{r}.{c}" (on a multilingual site). */
    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        $table = Table::fromStored($value);
        if (!$locales->isMultilingual() || $table === null) {
            return [];
        }
        $texts = [];
        foreach ($table->head ?? [] as $c => $cell) {
            if (!Table::isEmpty($cell)) {
                $texts[] = TranslatableText::of('head.' . $c, FolioText::get('folio.fields.table.header_cell', ['column' => (string) ($c + 1)]), $cell, $locales);
            }
        }
        foreach ($table->rows as $r => $row) {
            foreach ($row as $c => $cell) {
                if (!Table::isEmpty($cell)) {
                    $texts[] = TranslatableText::of('rows.' . $r . '.' . $c, FolioText::get('folio.fields.table.cell', ['row' => (string) ($r + 1), 'column' => (string) ($c + 1)]), $cell, $locales);
                }
            }
        }

        return $texts;
    }

    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed
    {
        $table = Table::fromStored($value);
        if ($table === null) {
            return $value;
        }
        $data = $table->toStored();
        foreach ($texts as $path => $text) {
            $parts = explode('.', (string) $path);
            if ($parts[0] === 'head' && count($parts) === 2 && isset($data['head'][(int) $parts[1]])) {
                $data['head'][(int) $parts[1]] = self::withText($data['head'][(int) $parts[1]], $text, $locale, $locales);
            } elseif ($parts[0] === 'rows' && count($parts) === 3 && isset($data['rows'][(int) $parts[1]][(int) $parts[2]])) {
                $data['rows'][(int) $parts[1]][(int) $parts[2]] = self::withText($data['rows'][(int) $parts[1]][(int) $parts[2]], $text, $locale, $locales);
            }
        }

        return $data;
    }

    /**
     * @param string|array<string, string> $cell
     * @return array<string, string>
     */
    private static function withText(string|array $cell, string $text, string $locale, Locales $locales): array
    {
        $map = LocaleValue::map($cell === '' ? null : $cell, $locales);
        $map[$locale] = $text;

        return array_filter($map, static fn (mixed $t): bool => is_string($t) && $t !== '');
    }

    /**
     * The rows and columns may change in this language: the default language, a single-language
     * site, or a table with nothing stored yet.
     *
     * @param array{head: list<string|array<string, string>>, rows: list<list<string|array<string, string>>>, columns: int} $grid
     */
    private static function editsStructure(string $locale, Locales $locales, array $grid): bool
    {
        return !$locales->isMultilingual() || $locale === $locales->default || $grid['columns'] === 0;
    }

    /**
     * A stored value as a grid: the header cells (empty ones without a header row), the rows and
     * the number of columns (0: nothing stored).
     *
     * @return array{head: list<string|array<string, string>>, rows: list<list<string|array<string, string>>>, columns: int}
     */
    private static function grid(mixed $value): array
    {
        $table = Table::fromStored(is_array($value) || is_string($value) ? $value : null);
        if ($table === null) {
            $data = Link::decode($value);
            $rows = is_array($data['rows'] ?? null) ? array_values(array_filter($data['rows'], 'is_array')) : [];
            $rows = array_map(static fn (array $row): array => array_map(Table::cell(...), array_values($row)), $rows);
            $head = is_array($data['head'] ?? null) ? array_map(Table::cell(...), array_values($data['head'])) : [];

            return ['head' => $head, 'rows' => $rows, 'columns' => max(count($head), ...array_map('count', $rows ?: [[]]))];
        }

        return ['head' => $table->head ?? array_fill(0, $table->columns(), ''), 'rows' => $table->rows, 'columns' => $table->columns()];
    }

    /**
     * @param list<string|array<string, string>> $row
     * @return list<string|array<string, string>>
     */
    private static function emptyAsText(array $row): array
    {
        return array_map(static fn (string|array $cell): string|array => $cell === [] ? '' : $cell, $row);
    }
}
