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

    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx): mixed
    {
        $input = is_array($input) ? $input : [];
        $order = is_array($input['_order'] ?? null) ? $input['_order'] : [];
        $byUid = [];
        foreach ($this->fromStorage($existing) as $block) {
            if (is_array($block) && is_string($block['_uid'] ?? null)) {
                $byUid[$block['_uid']] = $block;
            }
        }
        $multilingual = $ctx->locales->isMultilingual();

        $out = [];
        foreach ($order as $uid) {
            if (!is_string($uid) || preg_match(self::UID, $uid) !== 1 || !is_array($input[$uid] ?? null)) {
                continue;
            }
            $data = $input[$uid];
            $key = $data['_block'] ?? null;
            if (!is_string($key) || $key === '') {
                continue;
            }
            $previous = $byUid[$uid] ?? [];
            $definition = $this->blocks->get($key);

            if ($definition === null) {
                // Unknown block (class removed): keep the stored data verbatim.
                $raw = is_string($data['_raw'] ?? null) ? json_decode($data['_raw'], true) : null;
                if ($previous !== []) {
                    $out[] = $previous;
                } elseif (is_array($raw) && ($raw['_uid'] ?? null) === $uid) {
                    $out[] = $raw;
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
                        : $type->buildValue($data[$name] ?? [], $previous[$name] ?? null, is_array($blockFiles[$name] ?? null) ? $blockFiles[$name] : [], $ctx->deeper());
                    continue;
                }

                if ($type instanceof HandlesUpload) {
                    $removed = array_values(array_filter((array) ($data[$name . '_remove'] ?? []), 'is_string'));
                    $kept = array_values(array_diff(self::pathList($type->fromStorage($previous[$name] ?? null)), $removed));
                    $uploaded = $ctx->writeUploads ? self::uploadedFiles($blockFiles[$name] ?? null) : [];
                    $block[$name] = $type->toStorage($type->storeUploads($uploaded, $kept, $field->config));
                    continue;
                }

                $value = $type->toStorage($type->normalizeInput($data[$name] ?? null, $field->config));
                if ($field->translatable && $multilingual) {
                    $map = LocaleValue::map($previous[$name] ?? null, $ctx->locales);
                    $map[$ctx->locale] = $value;
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
     * @param list<array<string, mixed>>  $blocks
     * @param array<string, mixed>        $config
     * @param list<string>                $errors       field-level errors (min/max)
     * @param array<string, list<string>> $nestedErrors keys "<uid>.<field>…" relative to this field
     */
    private function renderField(string $name, string $label, ?string $help, array $blocks, array $config, array $errors, array $nestedErrors, Locales $locales, string $locale, int $depth): string
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
                : $this->renderCard($definition, $block, $name, self::scoped($nestedErrors, $uid), $locales, $locale, $depth);
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
    private function renderCard(\Preflow\Folio\Block\BlockDefinition $definition, array $block, string $namePrefix, array $errors, Locales $locales, string $locale, int $depth, bool $open = false): string
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

    /** @return list<array<string, mixed>> */
    public function fromStorage(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
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
