<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
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
final class BlocksFieldType implements FieldType, StructuredFieldType
{
    private const UID = '/^[a-f0-9]{16}$/';

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly int $maxDepth = 4,
        private readonly ?ValidatorFactory $validators = null,
        private readonly ?TemplateEngineInterface $engine = null,
        private readonly string $adminPrefix = '/folio',
    ) {}

    public function key(): string
    {
        return 'blocks';
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
            foreach ($definition->fields as $name => $field) {
                $type = $this->fieldTypes->get($field->type);

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
            $out[] = $block;
        }

        return $out;
    }

    /**
     * Per-instance validation. Keys: "<uid>.<field>", nested
     * "<uid>.<child>.<cuid>.<field>", "" and "<uid>.<child>" for min/max.
     *
     * @param array<string, mixed> $config the blocks field's config (allowed/min/max)
     * @return array<string, list<string>>
     */
    public function validateBlocks(mixed $value, array $config, Locales $locales, string $prefix = ''): array
    {
        $list = $this->fromStorage($value);
        $errors = [];

        $count = count($list);
        if (isset($config['min']) && $count < (int) $config['min']) {
            $errors[rtrim($prefix, '.')][] = sprintf('Add at least %d block%s.', (int) $config['min'], (int) $config['min'] === 1 ? '' : 's');
        }
        if (isset($config['max']) && $count > (int) $config['max']) {
            $errors[rtrim($prefix, '.')][] = sprintf('At most %d block%s allowed.', (int) $config['max'], (int) $config['max'] === 1 ? '' : 's');
        }

        foreach ($list as $block) {
            if (!is_array($block) || !is_string($block['_uid'] ?? null)) {
                continue;
            }
            $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
            if ($definition === null) {
                continue;
            }
            $uid = $block['_uid'];
            $rules = [];
            $data = [];
            foreach ($definition->fields as $name => $field) {
                $type = $this->fieldTypes->get($field->type);
                if ($type instanceof self) {
                    $errors += $type->validateBlocks($block[$name] ?? [], $field->config, $locales, $prefix . $uid . '.' . $name . '.');
                    continue;
                }
                $fieldRules = array_values(array_unique([...$field->validate, ...$type->rules($field->config)]));
                if ($fieldRules === []) {
                    continue;
                }
                $rules[$name] = $fieldRules;
                $data[$name] = $field->translatable
                    ? LocaleValue::pick($block[$name] ?? null, $locales, $locales->default)
                    : LocaleValue::default($block[$name] ?? null, $locales);
            }

            if ($rules !== [] && $this->validators !== null) {
                $result = $this->validators->make($rules, $data)->validate();
                foreach ($result->errors() as $field => $messages) {
                    $errors[$prefix . $uid . '.' . $field] = $messages;
                }
            }
        }

        return $errors;
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
            0,
            $ctx->record,
        );
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
     * inside a record's data, or null.
     *
     * @param list<string>         $keys  ["sections", "<uid>", "links", …]
     * @param array<string, mixed> $data  the record's stored data
     */
    public function storedLevel(array $data, array $keys): mixed
    {
        $value = $data[array_shift($keys)] ?? null;
        while ($keys !== []) {
            $uid = array_shift($keys);
            $field = array_shift($keys);
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

    /** @param array<string, mixed> $block */
    private function withFreshUids(array $block): array
    {
        $block['_uid'] = bin2hex(random_bytes(8));
        $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
        foreach ($definition?->fields ?? [] as $name => $field) {
            if ($this->fieldTypes->get($field->type) instanceof self && is_array($block[$name] ?? null)) {
                $block[$name] = array_map(fn ($child) => is_array($child) ? $this->withFreshUids($child) : $child, $this->fromStorage($block[$name]));
            }
        }

        return $block;
    }

    /**
     * @param list<array<string, mixed>>  $blocks
     * @param array<string, mixed>        $config
     * @param list<string>                $errors       field-level errors (min/max)
     * @param array<string, list<string>> $nestedErrors keys "<uid>.<field>…" relative to this field
     */
    private function renderField(string $name, string $label, ?string $help, array $blocks, array $config, array $errors, array $nestedErrors, Locales $locales, string $locale, int $depth, ?array $record = null): string
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
                : $this->renderCard($definition, $block, $name, self::scoped($nestedErrors, $uid), $locales, $locale, $depth, record: $record);
        }

        $summary = [];
        foreach ($nestedErrors as $key => $messages) {
            $summary[] = ['uid' => explode('.', $key)[0], 'messages' => $messages];
        }

        $allowed = array_values((array) ($config['allowed'] ?? array_keys($this->blocks->all())));
        $groups = [];
        foreach ($this->blocks->grouped($allowed) as $group => $definitions) {
            foreach ($definitions as $definition) {
                $groups[$group][] = [
                    'key' => $definition->key,
                    'label' => $definition->label,
                    'icon' => $definition->icon ?? 'block',
                    'description' => $definition->description,
                    'url' => $this->adminPrefix . '/_blocks/' . rawurlencode($definition->key) . '/new?'
                        . http_build_query(['name' => $name, 'locale' => $locale, 'depth' => $depth], '', '&', PHP_QUERY_RFC3986),
                ];
            }
        }

        return $this->render('@folio/admin/blocks/field.twig', [
            'name' => $name,
            'label' => $label,
            'help' => $help,
            'cards' => $cards,
            'errors' => $errors,
            'summary' => $summary,
            'groups' => $groups,
            'single' => count($groups) === 1 && count(reset($groups) ?: []) === 1,
        ]);
    }

    /**
     * @param array<string, mixed>        $block
     * @param array<string, list<string>> $errors keys "<field>…" relative to this block
     */
    private function renderCard(\Preflow\Folio\Block\BlockDefinition $definition, array $block, string $namePrefix, array $errors, Locales $locales, string $locale, int $depth, bool $open = false, ?array $record = null): string
    {
        $uid = (string) $block['_uid'];
        $base = $namePrefix . '[' . $uid . ']';
        $fields = [];
        $summary = '';

        foreach ($definition->fields as $field => $def) {
            $type = $this->fieldTypes->get($def->type);
            $raw = $block[$field] ?? null;

            if ($type instanceof self) {
                $fields[] = $type->renderField(
                    $base . '[' . $field . ']',
                    $def->label ?? ucfirst(str_replace('_', ' ', $field)),
                    $def->help,
                    $type->fromStorage($raw),
                    $def->config,
                    $errors[$field] ?? [],
                    self::scoped($errors, $field),
                    $locales,
                    $locale,
                    $depth + 1,
                    $record,
                );
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

            $fields[] = $type->renderEditor(new FieldContext(
                name: $base . '[' . $field . ']',
                label: $def->label,
                help: $def->help,
                value: $type->fromStorage($value),
                errors: $errors[$field] ?? [],
                config: $def->config,
                required: in_array('required', $def->validate, true) && (!$def->translatable || $locale === $locales->default),
                placeholder: $placeholder,
                locale: $locale,
                locales: $locales,
            ));
        }

        return $this->render('@folio/admin/blocks/card.twig', [
            'uid' => $uid,
            'key' => $definition->key,
            'label' => $definition->label,
            'icon' => $definition->icon ?? 'block',
            'summary' => $summary,
            'name' => $namePrefix,
            'fields' => $fields,
            'open' => $open || $errors !== [],
            'has_errors' => $errors !== [],
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

    public function toStorage(mixed $value): mixed
    {
        return $this->fromStorage($value);
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
            if (!is_string($block['_uid'] ?? null) || preg_match(self::UID, $block['_uid']) !== 1) {
                $block['_uid'] = hash('xxh3', 'folio-block:' . $index . ':' . json_encode($block['_uid'] ?? null) . ':' . json_encode($block['_block'] ?? null));
            }
            $blocks[] = $block;
        }

        return $blocks;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return ''; // rendered by folio_blocks() through the block components
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['htmx.js'];
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
