<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\AreasValue;
use Preflow\Data\Locales;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\AreasEditor;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\ReportsStoredTranslatableTexts;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;

/**
 * The areas field (0.92; the columns block's columns): a layout from a named area layout set and
 * the blocks of each area, config {"layouts": "<set>"}, stored as preflow/blocks' AreasValue
 * {"layout": key, "areas": {name: [blocks…]}}. Everything structural is the core's: the set
 * (AreaLayoutSets), the layout a value shows with and the areas it does not show (AreasValue,
 * Placement), and the checks of every write (BlockValueChecks in the areas shown, the areas'
 * allowed blocks and limits against the stored value, the layout key). This draws it and builds it
 * from the form.
 *
 * Each area is a blocks field one level deeper (max_depth counts it like a nested blocks field).
 * The editor is the page layout editor (AreasEditor, part "whole"): the picker posts
 * "<name>[layout]", each area "<name>[areas][<area>]"; a switch or a move is POST {admin}/_areas
 * with "set" (BlockEditorController::areas()), and an area a narrower layout lacks is kept in the
 * panel "Not shown in this layout". A save keeps every area the form does not post.
 *
 * Translation walks the areas the layout shows, each as a blocks field ("areas.<area>.<uid>.<field>"),
 * and takes translations back at those paths. Asset usage, rich text, show_when and fresh uids reach
 * the blocks inside through preflow/blocks' BlockLists, as for a blocks field.
 */
final class AreasFieldType implements FieldType, StructuredFieldType, DisplaysInAdmin, ReportsStoredTranslatableTexts, AcceptsTranslatedTexts
{
    /** An area name as the form posts it. */
    private const string AREA = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param \Closure(): AreaLayoutSets $sets   the sets packages register, asked on use
     * @param \Closure(): ?AreasEditor   $editor the layout editor, asked when the field is drawn
     */
    public function __construct(
        private readonly BlocksFieldType $blocks,
        private readonly \Closure $sets,
        private readonly \Closure $editor,
        private readonly ?TemplateEngineInterface $engine = null,
    ) {}

    public function key(): string
    {
        return 'areas';
    }

    /**
     * The value from the form over the stored one: the posted layout (else the stored one) and each
     * posted area built as a blocks field (BlocksFieldType::buildValue(); an area posted empty is
     * emptied). An area the form does not post keeps its stored blocks; an area the set's layouts
     * do not declare is never posted by the form and is left out.
     */
    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx, array $config = []): mixed
    {
        $stored = AreasValue::fromStored($existing);
        $input = is_array($input) ? $input : [];
        $layout = is_string($input['layout'] ?? null) && $input['layout'] !== '' ? $input['layout'] : $stored->layout;
        $declared = array_flip($this->declaredAreas($config));
        $areas = $stored->areas;
        $uploads = is_array($files['areas'] ?? null) ? $files['areas'] : [];
        foreach (is_array($input['areas'] ?? null) ? $input['areas'] : [] as $name => $post) {
            $name = (string) $name;
            if (preg_match(self::AREA, $name) !== 1 || !is_array($post) || (!isset($declared[$name]) && !array_key_exists($name, $areas))) {
                continue;
            }
            // Whether an area takes a block is the core's to say (AreaContents), as for a page's areas.
            $built = $this->blocks->buildValue($post, $areas[$name] ?? [], is_array($uploads[$name] ?? null) ? $uploads[$name] : [], $ctx);
            if ($built !== [] || array_key_exists($name, $areas)) {
                $areas[$name] = $built;
            }
        }

        return AreasValue::of($layout, $areas)->toStored();
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $editor = $this->renderAreas(
            $ctx->name,
            $ctx->value,
            $ctx->config,
            $ctx->locales ?? Locales::single($ctx->locale ?? 'en'),
            $ctx->locale ?? ($ctx->locales->default ?? 'en'),
            $ctx->depth,
            $ctx->nestedErrors,
            $ctx->record,
        );
        if ($this->engine === null) {
            throw new \LogicException('AreasFieldType needs a template engine to render the editor.');
        }

        return $this->engine->render('@folio/admin/areas/field.twig', [
            'label' => $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name)),
            'help' => $ctx->help,
            'errors' => $ctx->errors,
            'editor' => $editor,
        ]);
    }

    /**
     * The layout editor of a value under a form name (the field, or the switch's answer): the
     * picker of the set's layouts, the areas of the layout the value shows with, the panel "Not
     * shown in this layout", and the notice when its stored key is not in the set any more.
     *
     * @param array<string, mixed>        $config the field's config ("layouts": the set)
     * @param array<string, list<string>> $nestedErrors keys relative to the field: "layout", "areas.<area>", "areas.<area>.<uid>.<field>…"
     * @param array{type: string, id: ?string}|null $record
     */
    public function renderAreas(string $name, mixed $value, array $config, Locales $locales, string $locale, int $depth, array $nestedErrors, ?array $record): string
    {
        $editor = ($this->editor)();
        if (!$editor instanceof AreasEditor) {
            throw new \LogicException('AreasFieldType needs the layout editor (AreasEditor) to render.');
        }
        $set = $this->layoutSet($config);
        $areas = AreasValue::fromStored($value);
        $dotted = str_replace(['][', '[', ']'], ['.', '.', ''], $name);
        $errors = [];
        foreach ($nestedErrors as $key => $messages) {
            $errors[$dotted . '.' . $key] = $messages;
        }

        return $editor->render(
            $name . '[layout]',
            $name . '[areas]',
            $set->all(),
            $areas->resolve($set),
            $areas->placement($set),
            null,
            $areas->fallback($set) ? $areas->layout : null,
            $locales,
            $locale,
            $depth,
            $errors,
            $record,
            AreasEditor::WHOLE,
            $set->name,
        );
    }

    /**
     * The texts of the blocks in the areas the layout shows, each area walked as a blocks field:
     * paths "areas.<area>.<uid>.<field>", labels "Column 1 › Text › Body".
     */
    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        return $this->texts($value, $config, $locales, false);
    }

    /**
     * Every stored text: the areas the layout shows, then those it lacks (kept, not shown), each with
     * the hidden fields of its blocks; what a save keeps the stamps and machine marks of, so a switch
     * to fewer columns and back finds them intact.
     */
    public function storedTranslatableTexts(mixed $value, array $config, Locales $locales): array
    {
        return $this->texts($value, $config, $locales, true);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<\Preflow\Folio\Content\TranslatableText>
     */
    private function texts(mixed $value, array $config, Locales $locales, bool $stored): array
    {
        $set = $this->layoutSet($config);
        $areas = AreasValue::fromStored($value);
        $layout = $areas->resolve($set);
        $placement = $areas->placement($set);
        $texts = [];
        foreach ($stored ? $placement->placed() + $placement->areas() : $placement->placed() as $name => $list) {
            $label = FolioText::label($layout?->areas[$name]->label ?? $this->labelOf((string) $name, $set));
            $inner = $stored ? $this->blocks->storedTranslatableTexts($list, [], $locales) : $this->blocks->translatableTexts($list, [], $locales);
            foreach ($inner as $text) {
                $texts[] = $text->within('areas.' . $name, $label);
            }
        }

        return $texts;
    }

    /** An area the layout lacks, by the label another layout of the set gives it, else its name. */
    private function labelOf(string $name, AreaLayoutSet $set): string
    {
        foreach ($set->all() as $layout) {
            if (isset($layout->areas[$name])) {
                return $layout->areas[$name]->label;
            }
        }

        return $name;
    }

    /** Translations at the paths translatableTexts() reports ("areas.<area>.<uid>.<field>…"), each area as a blocks field. */
    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed
    {
        $areas = AreasValue::fromStored($value);
        $byArea = [];
        foreach ($texts as $path => $text) {
            $parts = explode('.', (string) $path, 3);
            if (count($parts) === 3 && $parts[0] === 'areas' && isset($areas->areas[$parts[1]])) {
                $byArea[$parts[1]][$parts[2]] = $text;
            }
        }
        $lists = $areas->areas;
        foreach ($byArea as $name => $given) {
            $lists[$name] = $this->blocks->withTranslatedTexts($lists[$name], $given, $locale, [], $locales);
        }

        return $this->fromStorage(AreasValue::of($areas->layout, $lists)->toStored());
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return []; // built through buildValue() (StructuredFieldType)
    }

    public function toStorage(mixed $value): mixed
    {
        return AreasValue::fromStored($value)->toStored();
    }

    /**
     * The stored value {layout, areas}, each area's blocks with a uid (a derived one for a block
     * without a valid one, as a blocks field gives it).
     *
     * @return array{layout: ?string, areas: array<string, list<array<string, mixed>>>}
     */
    public function fromStorage(mixed $value): array
    {
        $areas = AreasValue::fromStored($value);
        $lists = [];
        foreach ($areas->areas as $name => $list) {
            $lists[$name] = $this->blocks->fromStorage($list);
        }

        return AreasValue::of($areas->layout, $lists)->toStored();
    }

    /**
     * "3 blocks" (in every area, shown or not): a summary; the frontend output is the block's template's.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $n = array_sum(array_map('count', AreasValue::fromStored($value)->areas));

        return $n === 0 ? '' : FolioText::choice('folio.blocks.count', $n);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return ''; // the block that holds the field renders its areas (blocks() per area)
    }

    /** None of its own: the core checks the value on every write (BlockValueChecks, AreasFieldChecks). */
    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return $this->blocks->assets();
    }

    /**
     * The area names the set's layouts declare: an area by one of them may hold blocks whichever
     * layout is chosen (kept, not shown, while the layout lacks it).
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function declaredAreas(array $config): array
    {
        $names = [];
        foreach ($this->layoutSet($config)->all() as $layout) {
            foreach ($layout->areaNames() as $name) {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * The set the field's config names (the boot refuses a field naming none that is registered).
     *
     * @param array<string, mixed> $config
     * @throws \Preflow\Blocks\Layouts\LayoutConfigException when it names none that is registered
     */
    public function layoutSet(array $config): AreaLayoutSet
    {
        $sets = ($this->sets)();
        $sets->assertNamed($config['layouts'] ?? null);

        return $sets->get((string) $config['layouts']);
    }
}
