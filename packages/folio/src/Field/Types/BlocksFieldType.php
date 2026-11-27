<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Blocks\BlockRegistry;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An ordered list of inline blocks owned by the record:
 * [{"_uid": "…", "_block": "<key>", <field>: <value>…}, …]. Blocks nest via
 * their own "blocks" fields; translatable leaves are locale maps, structure
 * is shared across locales. Unknown block keys are kept untouched.
 */
final class BlocksFieldType implements FieldType, StructuredFieldType, \Preflow\Folio\Field\DisplaysInAdmin, \Preflow\Folio\Field\ReportsStoredTranslatableTexts, AcceptsTranslatedTexts, \Preflow\Folio\Field\UsesFieldKinds
{
    use \Preflow\Folio\Field\Types\CoreValue;

    private const UID = '/^[a-f0-9]{16}$/';

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly int $maxDepth = 4,
        private readonly ?ValidatorFactory $validators = null,
        private readonly ?TemplateEngineInterface $engine = null,
        private readonly string $adminPrefix = '/folio',
        /** The data package's field kinds: a field type's core checks (FieldKinds::rules(), 0.91) apply inside blocks too. */
        private readonly ?\Preflow\Data\FieldKinds $kinds = null,
        /** The core's block value checks (0.92), the app's instance; without it validateBlocks() builds its own. */
        private ?\Preflow\Blocks\BlockValueChecks $checks = null,
        /** The app's area layout sets (0.92): the checks validateBlocks() builds see the columns a layout shows only. */
        private readonly ?\Preflow\Blocks\Layouts\AreaLayoutSets $sets = null,
    ) {}

    public function key(): string
    {
        return 'blocks';
    }

    /**
     * The core value of this type (used without kinds, or when they hold none for the key): the
     * block fields through the kinds given to the constructor, none without them (unchanged).
     */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new \Preflow\Blocks\Values\BlocksFieldValue(fn (): BlockRegistry => $this->blocks, $this->kinds ?? new \Preflow\Data\FieldKinds());
    }

    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx, array $config = []): mixed
    {
        $input = is_array($input) ? $input : [];
        $order = is_array($input['_order'] ?? null) ? $input['_order'] : [];
        $byUid = [];
        foreach ($this->fromStorage($existing) as $block) {
            $byUid[$block['_uid']] = $block;
        }
        $multilingual = $ctx->locales->isMultilingual();
        $allowed = isset($config['allowed']) ? array_values((array) $config['allowed']) : null;

        $out = [];
        foreach ($order as $uid) {
            if (!is_string($uid) || preg_match(self::UID, $uid) !== 1) {
                continue;
            }
            $previous = $byUid[$uid] ?? [];
            $data = $input[$uid] ?? null;
            $key = is_array($data) && is_string($data['_block'] ?? null) ? $data['_block'] : '';

            if ($key === '') {
                // Listed but no data (e.g. PHP's max_input_vars truncated the POST):
                // keep what is stored rather than losing it.
                if ($previous !== []) {
                    $out[] = $previous;
                }
                continue;
            }

            $definition = $this->blocks->get($key);
            if ($definition === null) {
                // Unknown block (class removed): keep the stored data verbatim. A posted
                // _raw copy is accepted only for that same unknown key (duplicates of
                // unknown children), never to create a registered block.
                $raw = is_string($data['_raw'] ?? null) ? json_decode($data['_raw'], true) : null;
                if ($previous !== []) {
                    $out[] = $previous;
                } elseif (is_array($raw) && ($raw['_uid'] ?? null) === $uid && ($raw['_block'] ?? null) === $key) {
                    $out[] = $raw;
                }
                continue;
            }

            if ($allowed !== null && !in_array($key, $allowed, true)) {
                // Not allowed here: never accept a new one; keep a stored one (allowed lists change).
                if ($previous !== [] && ($previous['_block'] ?? null) === $key) {
                    $out[] = $previous;
                }
                continue;
            }

            $block = ['_uid' => $uid, '_block' => $key];
            $blockFiles = is_array($files[$uid] ?? null) ? $files[$uid] : [];
            // A field its show_when hides (0.91), decided on the posted sources before anything is
            // built or uploaded: it keeps its stored value, and a file posted for it is never written.
            $hidden = $this->blocks->hasShowWhen() ? array_flip($this->blocks->hiddenFieldsOf($key, $this->sourceValues($definition, $data, $previous))) : [];
            foreach ($definition->fields as $name => $field) {
                $type = $this->fieldTypes->get($field->type);
                $this->assertLeaf($definition, $name, $field, $type);
                if (isset($hidden[$name])) {
                    if (array_key_exists($name, $previous)) {
                        $block[$name] = $previous[$name];
                    }
                    continue;
                }

                if ($type instanceof StructuredFieldType) {
                    $block[$name] = $ctx->depth + 1 >= $this->maxDepth
                        ? ($previous[$name] ?? [])
                        : $type->buildValue($data[$name] ?? [], $previous[$name] ?? null, is_array($blockFiles[$name] ?? null) ? $blockFiles[$name] : [], $ctx->deeper(), $field->config);
                    continue;
                }

                if ($type instanceof AssetFieldType) {
                    // The posted selection is authoritative (a duplicate carries its files this way);
                    // without one the stored value stays.
                    $selection = $data[$name . '_ids'] ?? null;
                    $block[$name] = $type->resolve(
                        is_array($selection) ? array_values($selection) : null,
                        $previous[$name] ?? null,
                        $ctx->writeUploads ? self::uploadedFiles($blockFiles[$name] ?? null) : [],
                        $field->config,
                        $ctx->writeUploads,
                        null,
                    );
                    continue;
                }

                if ($type instanceof HandlesUpload) {
                    $current = self::pathList($type->fromStorage($previous[$name] ?? null));
                    $uploaded = $ctx->writeUploads ? self::uploadedFiles($blockFiles[$name] ?? null) : [];
                    $block[$name] = $type->toStorage($type->storeUploads($uploaded, $current, $field->config));
                    continue;
                }

                $value = $type->toStorage($type->normalizeInput($data[$name] ?? null, $field->config));
                if ($field->translatable && $multilingual) {
                    $map = LocaleValue::map($previous[$name] ?? null, $ctx->locales);
                    $map[$ctx->locale] = $value;
                    if ($previous === [] && $ctx->locale !== $ctx->locales->default) {
                        // A block created while editing another language starts with the
                        // same text in the default language, so "required" never blocks it.
                        $map[$ctx->locales->default] ??= $value;
                    }
                    $value = $map;
                }
                $block[$name] = $value;
            }
            // A field its show_when hides keeps its stored value, whatever was posted (0.91; its input
            // is disabled in the editor and does not post).
            $out[] = $this->blocks->hasShowWhen() ? \Preflow\Data\FieldVisibility::keepStored($definition->fields, $block, $previous) : $block;
        }

        return $out;
    }

    /**
     * The translatable leaves of every known block, walked like validateBlocks():
     * "<uid>.<field>", nested "<uid>.<child>.<cuid>.<field>", labelled
     * "Hero › Headline". Unknown blocks are skipped, and so are the fields a block's show_when
     * hides (0.91): a hidden text is neither missing nor outdated, and is not sent to a translator.
     */
    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        return $this->texts($value, $locales, false);
    }

    /**
     * Every text of the known blocks, the fields their show_when hides and, inside an areas field,
     * the columns its layout does not show included (0.92): what a save keeps the stamps of.
     */
    public function storedTranslatableTexts(mixed $value, array $config, Locales $locales): array
    {
        return $this->texts($value, $locales, true);
    }

    /**
     * @param bool $stored every stored text (storedTranslatableTexts()), not only the shown ones
     * @return list<TranslatableText>
     */
    private function texts(mixed $value, Locales $locales, bool $stored): array
    {
        if (!$locales->isMultilingual()) {
            return [];
        }

        $texts = [];
        foreach ($this->fromStorage($value) as $block) {
            $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
            if ($definition === null) {
                continue;
            }
            $prefix = $block['_uid'];
            $blockLabel = \Preflow\Folio\FolioText::label($definition->label);
            $hidden = $stored ? [] : array_flip($this->blocks->hiddenFieldsOf($definition->key, $block));
            foreach ($definition->fields as $name => $field) {
                if (isset($hidden[$name])) {
                    continue;
                }
                $type = $this->fieldTypes->get($field->type);
                $label = $blockLabel . ' › ' . self::fieldLabel($field->label, $name);
                if ($type instanceof ReportsTranslatableTexts) {
                    $nested = $stored && $type instanceof \Preflow\Folio\Field\ReportsStoredTranslatableTexts
                        ? $type->storedTranslatableTexts($type->fromStorage($block[$name] ?? null), $field->config, $locales)
                        : $type->translatableTexts($type->fromStorage($block[$name] ?? null), $field->config, $locales);
                    foreach ($nested as $text) {
                        $texts[] = $text->within($prefix . '.' . $name, $label);
                    }
                    continue;
                }
                if ($field->translatable && TranslationStatus::holdsText($type)) {
                    $texts[] = TranslatableText::of($prefix . '.' . $name, $label, $block[$name] ?? null, $locales, format: TranslationStatus::format($type));
                }
            }
        }

        return $texts;
    }

    /**
     * Sets translations at the paths translatableTexts() reports: "<uid>.<field>" leaves as locale
     * maps (the text normalized as a form save would), deeper paths through a nested field that
     * accepts them. Unknown blocks, fields and paths are left alone, as are structure and order.
     */
    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed
    {
        $blocks = $this->fromStorage($value);
        if (!$locales->isMultilingual() || !$locales->has($locale)) {
            return $blocks;
        }

        // uid => field => [rest path ('' for the leaf itself) => text]
        $byBlock = [];
        foreach ($texts as $path => $text) {
            $parts = explode('.', (string) $path, 3);
            if (count($parts) >= 2 && is_string($text)) {
                $byBlock[$parts[0]][$parts[1]][$parts[2] ?? ''] = $text;
            }
        }

        foreach ($blocks as $i => $block) {
            $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
            if ($definition === null || !isset($byBlock[$block['_uid']])) {
                continue;
            }
            foreach ($byBlock[$block['_uid']] as $name => $given) {
                $field = $definition->fields[$name] ?? null;
                if ($field === null) {
                    continue;
                }
                $type = $this->fieldTypes->get($field->type);
                if ($type instanceof AcceptsTranslatedTexts) {
                    $nested = array_filter($given, static fn (string $rest): bool => $rest !== '', ARRAY_FILTER_USE_KEY);
                    $before = $type->fromStorage($block[$name] ?? null);
                    $after = $nested === [] ? $before : $type->withTranslatedTexts($before, $nested, $locale, $field->config, $locales);
                    if ($after !== $before) {
                        $blocks[$i][$name] = $type->toStorage($after);
                    }
                    continue;
                }
                if (isset($given['']) && $field->translatable && TranslationStatus::holdsText($type)) {
                    $map = LocaleValue::map($block[$name] ?? null, $locales);
                    $map[$locale] = $type->toStorage($type->normalizeInput($given[''], $field->config));
                    $blocks[$i][$name] = $map;
                }
            }
        }

        return $blocks;
    }

    /**
     * Per-instance validation, the core's (BlockValueChecks): keys "<uid>.<field>", nested
     * "<uid>.<child>.<cuid>.<field>", and "" and "<uid>.<child>" for min/max (BlockCount). A record's
     * save runs the same checks in the data layer; this is for callers that judge one value.
     *
     * @param array<string, mixed> $config the blocks field's config (allowed/min/max)
     * @return array<string, list<string>>
     */
    public function validateBlocks(mixed $value, array $config, Locales $locales, string $prefix = ''): array
    {
        $list = $this->fromStorage($value);
        $errors = [];
        foreach ($this->rules($config) as $rule) {
            $result = $rule->validate($list, new \Preflow\Validation\ValidationContext(rtrim($prefix, '.'), []));
            if ($result !== true) {
                $errors[rtrim($prefix, '.')][] = (string) $result;
            }
        }
        $checks = $this->valueChecks();

        return $checks === null ? $errors : $errors + $checks->blocks($list, $locales, $prefix);
    }

    /**
     * The core's block value checks with Folio's field rules: the app's instance, else (a field type
     * built on its own, without a validator factory none) one over this registry.
     */
    private function valueChecks(): ?\Preflow\Blocks\BlockValueChecks
    {
        if ($this->checks === null && $this->validators !== null) {
            $kinds = $this->kinds ?? new \Preflow\Data\FieldKinds();
            $this->checks = new \Preflow\Blocks\BlockValueChecks($this->blocks, $kinds, $this->validators, sets: $this->sets);
            \Preflow\Folio\Field\CoreFieldRules::register($this->fieldTypes, $kinds, blocks: $this->checks);
        }

        return $this->checks;
    }

    public function renderEditor(FieldContext $ctx): string
    {
        return $this->renderField(
            $ctx->name,
            $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name)),
            $ctx->help,
            $this->fromStorage($ctx->value),
            $ctx->config,
            $ctx->errors,
            $ctx->nestedErrors,
            $ctx->locales ?? Locales::single($ctx->locale ?? 'en'),
            $ctx->locale ?? ($ctx->locales?->default ?? 'en'),
            $ctx->depth,
            $ctx->record,
        );
    }

    /**
     * A block list under any form name, rendered as a blocks field is: a page's area
     * ("_areas[main]", 0.92), and a column of an areas field. $value is the list, $config the
     * area as a blocks field ("allowed" for its picker, "min", "max"). The list posts an empty
     * "_order" entry before its cards, so a list without cards empties its area (an area that is
     * not posted keeps its blocks). $picker false: no "Add block" (the panel "Not shown in this
     * layout": its blocks are kept, moved or removed, never added to). $move: each card's "Move"
     * menu, to the areas $move['targets'] names for the block's key, through the switch and move
     * endpoint at $move['url'] (POST {admin}/_areas, value "<uid>:<area>").
     *
     * @param array<string, mixed>        $config
     * @param list<string>                $errors       the list's own errors
     * @param array<string, list<string>> $nestedErrors keys "<uid>.<field>…" relative to the list
     * @param array{type: string, id: ?string}|null $record
     * @param array{url: string, target: string, targets: \Closure(string): list<array{area: string, label: string}>}|null $move
     * @since 0.92.0
     */
    public function renderList(string $name, string $label, ?string $help, mixed $value, array $config, array $errors, array $nestedErrors, Locales $locales, string $locale, int $depth, ?array $record = null, bool $picker = true, ?array $move = null): string
    {
        return $this->renderField($name, $label, $help, $this->fromStorage($value), $config, $errors, $nestedErrors, $locales, $locale, $depth, $record, $picker, $move, emptiable: true);
    }

    /**
     * A fresh, empty card for the block picker (fragment endpoint).
     * Null when the key is not a known block.
     */
    public function renderNewCard(string $key, string $namePrefix, Locales $locales, string $locale, int $depth): ?string
    {
        $definition = $this->blocks->get($key);
        if ($definition === null || $depth >= $this->maxDepth) {
            return null;
        }

        return $this->renderCard($definition, ['_uid' => bin2hex(random_bytes(8)), '_block' => $key], $namePrefix, [], $locales, $locale, $depth, open: true);
    }

    /**
     * A copy of one block as a fresh card: the posted (current, unsaved) values
     * merged with the stored block (other locales, uploaded files), with new
     * uids throughout. Null when nothing valid was posted.
     *
     * @param mixed                     $posted the posted data of the block (name[uid])
     * @param mixed                     $stored the stored list at this level (or null)
     * @param array{type: string, id: ?string}|null $record
     */
    public function renderDuplicate(string $namePrefix, string $uid, mixed $posted, mixed $stored, Locales $locales, string $locale, int $depth, ?array $record = null): ?string
    {
        if (preg_match(self::UID, $uid) !== 1 || $depth >= $this->maxDepth) {
            return null;
        }
        $built = $this->buildValue(['_order' => [$uid], $uid => $posted], $stored, [], new BuildContext($locales, $locale, false, $depth));
        if ($built === []) {
            return null;
        }
        $copy = $this->withFreshUids($built[0]);
        $definition = $this->blocks->get((string) $copy['_block']);

        return $definition === null
            ? $this->render('@folio/admin/blocks/unknown.twig', [
                'uid' => $copy['_uid'],
                'key' => (string) $copy['_block'],
                'name' => $namePrefix,
                'raw' => json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            : $this->renderCard($definition, $copy, $namePrefix, [], $locales, $locale, $depth, open: true, record: $record);
    }

    /**
     * The stored block list at a field path such as "sections[<uid>][links]"
     * inside a record's data, or null. A page's area is "_areas[<area>]" (0.92), an areas field's
     * column "…[<field>][areas][<area>]".
     *
     * @param list<string>         $keys  ["sections", "<uid>", "links", …]
     * @param array<string, mixed> $data  the record's stored data
     */
    public function storedLevel(array $data, array $keys): mixed
    {
        $first = array_shift($keys);
        $value = $data[$first] ?? null;
        if ($first === \Preflow\Blocks\Layouts\PageLayouts::AREAS) {
            $value = \Preflow\Blocks\Layouts\Placement::areasOf($value)[(string) array_shift($keys)] ?? null;
        }
        while ($keys !== []) {
            $uid = array_shift($keys);
            $field = array_shift($keys);
            if ($uid === 'areas' && $field !== null) {
                // An areas value {layout, areas}: one of its areas.
                $areas = is_string($value) ? json_decode($value, true) : $value;
                $value = \Preflow\Blocks\Layouts\Placement::areasOf(is_array($areas) ? ($areas['areas'] ?? null) : null)[$field] ?? null;
                continue;
            }
            $found = null;
            foreach ($this->fromStorage($value) as $block) {
                if ($block['_uid'] === $uid) {
                    $found = $block;
                    break;
                }
            }
            if ($found === null || $field === null) {
                return null;
            }
            $value = $found[$field] ?? null;
        }

        return $value;
    }

    /**
     * The block with a new uid, and so has every block inside its fields that hold blocks (a blocks
     * field's, an areas field's columns: BlockLists).
     *
     * @param array<string, mixed> $block
     */
    private function withFreshUids(array $block): array
    {
        $block['_uid'] = bin2hex(random_bytes(8));
        $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
        foreach ($definition?->fields ?? [] as $name => $field) {
            if (\Preflow\Blocks\BlockLists::holds($field) && is_array($block[$name] ?? null)) {
                $block[$name] = \Preflow\Blocks\BlockLists::map($field, $block[$name], fn (array $list): array => array_map($this->withFreshUids(...), $list));
            }
        }

        return $block;
    }

    /**
     * @param list<array<string, mixed>>  $blocks
     * @param array<string, mixed>        $config
     * @param list<string>                $errors       field-level errors (min/max)
     * @param array<string, list<string>> $nestedErrors keys "<uid>.<field>…" relative to this field
     * @param array{type: string, id: ?string}|null $record
     * @param array{url: string, target: string, targets: \Closure(string): list<array{area: string, label: string}>}|null $move
     */
    private function renderField(string $name, string $label, ?string $help, array $blocks, array $config, array $errors, array $nestedErrors, Locales $locales, string $locale, int $depth, ?array $record = null, bool $picker = true, ?array $move = null, bool $emptiable = false): string
    {
        if ($depth >= $this->maxDepth) {
            return $this->render('@folio/admin/blocks/too_deep.twig', ['label' => $label]);
        }

        $cards = [];
        foreach ($blocks as $block) {
            $uid = is_string($block['_uid'] ?? null) ? $block['_uid'] : null;
            if ($uid === null || preg_match(self::UID, $uid) !== 1) {
                continue;
            }
            $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
            $cards[] = $definition === null
                ? $this->render('@folio/admin/blocks/unknown.twig', [
                    'uid' => $uid,
                    'key' => (string) ($block['_block'] ?? ''),
                    'name' => $name,
                    'raw' => json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])
                : $this->renderCard($definition, $block, $name, self::scoped($nestedErrors, $uid), $locales, $locale, $depth, record: $record, move: $move);
        }

        $summary = [];
        foreach ($nestedErrors as $key => $messages) {
            $summary[] = ['uid' => explode('.', $key)[0], 'messages' => $messages];
        }

        $allowed = array_values((array) ($config['allowed'] ?? array_keys($this->blocks->all())));
        $groups = [];
        foreach ($this->blocks->grouped($allowed) as $group => $definitions) {
            foreach ($definitions as $definition) {
                // Blocks without a group of their own sit under Folio's "Blocks", in the interface language.
                // A block's label, group and description may be keys (FolioText::label()).
                $groups[$group === \Preflow\Blocks\Block::DEFAULT_GROUP ? \Preflow\Folio\FolioText::get('folio.blocks.group') : \Preflow\Folio\FolioText::label($group)][] = [
                    'key' => $definition->key,
                    'label' => \Preflow\Folio\FolioText::label($definition->label),
                    'icon' => $definition->icon ?? 'block',
                    'description' => $definition->description !== null ? \Preflow\Folio\FolioText::label($definition->description) : null,
                    // The tile's tint (#[Block(family: …)]); admin.css maps it to colours.
                    'family' => $definition->family,
                    'url' => $this->adminPrefix . '/_blocks/' . rawurlencode($definition->key) . '/new?'
                        . http_build_query(['name' => $name, 'locale' => $locale, 'depth' => $depth], '', '&', PHP_QUERY_RFC3986),
                ];
            }
        }

        // The picker's search: each item is found by its label and description (lower case, as typed).
        $total = 0;
        foreach ($groups as $group => $items) {
            foreach ($items as $i => $item) {
                $groups[$group][$i]['search'] = mb_strtolower(trim($item['label'] . ' ' . ($item['description'] ?? '')));
                $total++;
            }
        }
        // "· 2 blocks", and both plural forms for admin.js, which keeps the count current as cards come and go.
        $forms = explode('|', \Preflow\Folio\FolioText::get('folio.blocks.count'));

        return $this->render('@folio/admin/blocks/field.twig', [
            'name' => $name,
            'label' => $label,
            'help' => $help,
            'cards' => $cards,
            'count' => count($cards),
            'count_text' => \Preflow\Folio\FolioText::choice('folio.blocks.count', count($cards)),
            'count_one' => $forms[0],
            'count_other' => $forms[1] ?? $forms[0],
            'errors' => $errors,
            'summary' => $summary,
            // Invalid cards are rendered open: then "Collapse all", else "Expand all".
            'any_open' => $summary !== [],
            'groups' => $groups,
            'total' => $total,
            // One popover per field: its id follows the field's name (unique in the form, nested ones too).
            'picker_id' => 'folio-picker-' . hash('xxh3', $name),
            'single' => count($groups) === 1 && count(reset($groups) ?: []) === 1,
            // A block list of an area (0.92, renderList()): it empties when its last card goes; the panel's has no picker.
            'emptiable' => $emptiable,
            'picker' => $picker,
        ]);
    }

    /**
     * @param array<string, mixed>        $block
     * @param array<string, list<string>> $errors keys "<field>…" relative to this block
     * @param array{type: string, id: ?string}|null $record
     * @param array{url: string, target: string, targets: \Closure(string): list<array{area: string, label: string}>}|null $move
     */
    private function renderCard(\Preflow\Blocks\BlockDefinition $definition, array $block, string $namePrefix, array $errors, Locales $locales, string $locale, int $depth, bool $open = false, ?array $record = null, ?array $move = null): string
    {
        $uid = (string) $block['_uid'];
        $base = $namePrefix . '[' . $uid . ']';
        $fields = [];
        $summary = '';
        // The block's values for every field's editor (FieldContext::$values, 0.90): a select's "options_from"
        // reads them, and so may any field type. Decoding only, no read.
        $values = $this->blockValues($definition, $block, $locales);
        // A field with show_when (0.91) sits in an element fields.js shows or hides as its source in this
        // card changes; hidden now, it is rendered hidden. Blocks without one render as before.
        $hidden = array_flip($this->blocks->hiddenFieldsOf($definition->key, $block));
        $conditional = function (string $field, string $html) use ($definition, $values, $base, $hidden): string {
            $rule = $definition->fields[$field]->config['show_when'] ?? null;

            return is_array($rule)
                ? \Preflow\Folio\Field\ShowWhenMarkup::wrap($html, $rule, $definition->fields, $values, $base, isset($hidden[$field]))
                : $html;
        };

        foreach ($definition->fields as $field => $def) {
            $type = $this->fieldTypes->get($def->type);
            $this->assertLeaf($definition, $field, $def, $type);
            $raw = $block[$field] ?? null;

            if (\Preflow\Blocks\BlockLists::holds($def)) {
                // A field holding blocks (a blocks field, an areas field's columns) one level deeper.
                $fields[] = $conditional($field, $type->renderEditor(new FieldContext(
                    name: $base . '[' . $field . ']',
                    label: self::fieldLabel($def->label, $field),
                    help: $def->help !== null ? \Preflow\Folio\FolioText::label($def->help) : null,
                    value: $type->fromStorage($raw),
                    errors: $errors[$field] ?? [],
                    config: $def->config,
                    nestedErrors: self::scoped($errors, $field),
                    locale: $locale,
                    locales: $locales,
                    record: $record,
                    depth: $depth + 1,
                )));
                continue;
            }

            $value = $def->translatable ? LocaleValue::pick($raw, $locales, $locale) : LocaleValue::default($raw, $locales);
            $placeholder = null;
            if ($def->translatable && ($value === null || $value === '') && $locale !== $locales->default) {
                [$resolved] = LocaleValue::resolve($raw, $locales, $locale);
                $placeholder = is_scalar($resolved) ? mb_substr(trim(strip_tags((string) $resolved)), 0, 120) : null;
            }
            if ($summary === '' && is_scalar($value) && trim(strip_tags((string) $value)) !== '' && in_array($def->type, ['string', 'text'], true)) {
                $summary = mb_substr(trim(strip_tags((string) $value)), 0, 80);
            }

            $config = $def->config;
            if (($source = SelectFieldType::sourceField($config)) !== null) {
                // The note while the source has no values names it as the block's editor does.
                $config['options_from_label'] = self::fieldLabel($definition->fields[$source]->label ?? null, $source);
            }

            $fields[] = $conditional($field, $type->renderEditor(new FieldContext(
                name: $base . '[' . $field . ']',
                label: self::fieldLabel($def->label, $field),
                help: $def->help !== null ? \Preflow\Folio\FolioText::label($def->help) : null,
                value: $type->fromStorage($value),
                errors: $errors[$field] ?? [],
                config: $config,
                required: in_array('required', $def->validate, true) && (!$def->translatable || $locale === $locales->default),
                placeholder: $placeholder,
                locale: $locale,
                locales: $locales,
                validate: $def->validate,
                values: $values,
            )));
        }

        // An invalid block shows its first message where the summary was ("Headline: This field is required.").
        $error = null;
        foreach ($errors as $key => $messages) {
            $message = is_array($messages) ? (string) (reset($messages) ?: '') : (string) $messages;
            if ($message === '') {
                continue;
            }
            $field = explode('.', (string) $key)[0];
            $def = $definition->fields[$field] ?? null;
            $error = $def !== null ? self::fieldLabel($def->label, $field) . ': ' . $message : $message;
            break;
        }

        return $this->render('@folio/admin/blocks/card.twig', [
            'uid' => $uid,
            'key' => $definition->key,
            'label' => \Preflow\Folio\FolioText::label($definition->label),
            'icon' => $definition->icon ?? 'block',
            'family' => $definition->family,
            'summary' => $summary,
            'error' => $error,
            'name' => $namePrefix,
            'fields' => $fields,
            'open' => $open || $errors !== [],
            'has_errors' => $errors !== [],
            // The panel "Not shown in this layout" (0.92): the areas of the layout this block may move to.
            'move' => $move === null ? null : [
                'url' => $move['url'],
                'target' => $move['target'],
                'items' => array_map(static fn (array $to): array => ['value' => $uid . ':' . $to['area'], 'label' => $to['label']], ($move['targets'])($definition->key)),
            ],
            'duplicate_url' => $this->adminPrefix . '/_blocks/duplicate?' . http_build_query([
                'name' => $namePrefix,
                'uid' => $uid,
                'locale' => $locale,
                'depth' => $depth,
                'type' => $record['type'] ?? '',
                'id' => $record['id'] ?? '',
            ], '', '&', PHP_QUERY_RFC3986),
        ]);
    }

    /**
     * The stored block with each show_when source as posted (normalized as buildValue() stores it);
     * a source posted as files or nested blocks counts with its stored value.
     *
     * @param array<string, mixed> $data the posted block
     * @param array<string, mixed> $previous the stored block ([] for a new one)
     * @return array<string, mixed>
     */
    private function sourceValues(\Preflow\Blocks\BlockDefinition $definition, array $data, array $previous): array
    {
        foreach (\Preflow\Data\FieldVisibility::sourcesOf($definition->fields) as $source) {
            $field = $definition->fields[$source] ?? null;
            if ($field === null) {
                continue;
            }
            $type = $this->fieldTypes->get($field->type);
            if ($type instanceof StructuredFieldType || $type instanceof AssetFieldType || $type instanceof HandlesUpload) {
                continue;
            }
            $previous[$source] = $type->toStorage($type->normalizeInput($data[$source] ?? null, $field->config));
        }

        return $previous;
    }

    /**
     * The block's values, field => value, as FieldContext::$values holds a record's: each in the
     * default language, as its own field type reads it; fields holding blocks (BlockLists) left out.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function blockValues(\Preflow\Blocks\BlockDefinition $definition, array $block, Locales $locales): array
    {
        $values = [];
        foreach ($definition->fields as $name => $field) {
            if (\Preflow\Blocks\BlockLists::holds($field)) {
                continue;
            }
            $type = $this->fieldTypes->get($field->type);
            $raw = $field->translatable
                ? LocaleValue::pick($block[$name] ?? null, $locales, $locales->default)
                : LocaleValue::default($block[$name] ?? null, $locales);
            $values[$name] = $type->fromStorage($raw);
        }

        return $values;
    }

    /**
     * Errors under "<prefix>." with the prefix removed.
     *
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    private static function scoped(array $errors, string $prefix): array
    {
        $out = [];
        foreach ($errors as $key => $messages) {
            if (str_starts_with((string) $key, $prefix . '.')) {
                $out[substr((string) $key, strlen($prefix) + 1)] = $messages;
            }
        }

        return $out;
    }

    /** A block field's label: its #[BlockField] label (a key or plain text), else the humanized name. */
    private static function fieldLabel(?string $label, string $field): string
    {
        return $label !== null ? \Preflow\Folio\FolioText::label($label) : ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * A block field the editor cannot hold: a translatable tags field (one list serves every
     * language, as on a type; TypeCatalog refuses it there at boot). @since 0.77
     * The options of preflow/fields' types are checked once, when the block registry is built
     * (FieldsServiceProvider adds them to BlockFieldChecks, 0.91), not here on every build and render.
     *
     * @throws \Preflow\Folio\Content\FrontendConfigException
     */
    private function assertLeaf(\Preflow\Blocks\BlockDefinition $definition, string $name, \Preflow\Data\TypeFieldDefinition $field, \Preflow\Folio\Field\FieldType $type): void
    {
        if ($type instanceof TagsFieldType && $field->translatable) {
            throw new \Preflow\Folio\Content\FrontendConfigException(sprintf(
                'Block "%s": field "%s": A tags field cannot be translatable; keep one list for every language.',
                $definition->key,
                $name,
            ));
        }
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        if ($this->engine === null) {
            throw new \LogicException('BlocksFieldType needs a template engine to render the editor.');
        }

        return $this->engine->render($template, $context);
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return []; // built through buildValue() (StructuredFieldType)
    }

    /**
     * The list as the editor holds it (fromStorage(): each block with its uid), each block's fields
     * as the core stores them (BlocksFieldValue: every field through its own value).
     */
    public function toStorage(mixed $value): mixed
    {
        return $this->stored($this->fromStorage($value));
    }

    /**
     * The stored list. Blocks without a valid uid (hand-edited or imported JSON)
     * get a stable derived one, so the editor can show and save them instead of
     * silently dropping them.
     *
     * @return list<array<string, mixed>>
     */
    public function fromStorage(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }

        $blocks = [];
        foreach (array_values(array_filter($value, 'is_array')) as $index => $block) {
            // The core derives the same uid (BlockLists::uidOf()), so its errors name the block the editor shows.
            $block['_uid'] = \Preflow\Blocks\BlockLists::uidOf($block, $index);
            $blocks[] = $block;
        }

        return $blocks;
    }

    /** "3 blocks": a summary; the frontend output is empty (blocks() renders them). */
    public function renderAdmin(mixed $value, array $config): string
    {
        $n = count($this->fromStorage($value));

        return $n === 0 ? '' : \Preflow\Folio\FolioText::choice('folio.blocks.count', $n);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return ''; // rendered by blocks() through the block components
    }

    /**
     * The field's "min" and "max" (BlockCount); the values inside are the core's (BlockValueChecks).
     *
     * @return list<\Preflow\Validation\ValidationRule>
     */
    public function rules(array $config): array
    {
        $min = isset($config['min']) ? (int) $config['min'] : null;
        $max = isset($config['max']) ? (int) $config['max'] : null;

        return $min === null && $max === null ? [] : [new BlockCount($min, $max)];
    }

    public function assets(): array
    {
        // htmx: picker and duplicate fragments; admin.js: collapse, move, remove; blocks.js (after it):
        // the picker's popover, the cards' menus, Collapse all. Plus whatever the fields inside any
        // block need (Trix for rich text…), since cards can be added after the page loaded.
        $assets = ['htmx.js', 'admin.js', 'blocks.js'];
        if ($this->blocks->hasShowWhen()) {
            $assets[] = 'show-when-core.js'; // before fields.js, which the editor loads last
        }
        foreach ($this->blocks->all() as $definition) {
            foreach ($definition->fields as $field) {
                // A field holding blocks (BlockLists) draws these same blocks: its assets are these.
                if (!\Preflow\Blocks\BlockLists::holds($field) && $this->fieldTypes->has($field->type)) {
                    $assets = [...$assets, ...$this->fieldTypes->get($field->type)->assets()];
                }
            }
        }

        return array_values(array_unique($assets));
    }

    /** @return UploadedFileInterface[] */
    private static function uploadedFiles(mixed $entry): array
    {
        if ($entry instanceof UploadedFileInterface) {
            return [$entry];
        }

        return is_array($entry) ? array_values(array_filter($entry, static fn ($f) => $f instanceof UploadedFileInterface)) : [];
    }

    /** @return string[] */
    private static function pathList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }

        return is_string($value) && $value !== '' ? [$value] : [];
    }
}
