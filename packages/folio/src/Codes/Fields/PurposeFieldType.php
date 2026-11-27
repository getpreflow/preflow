<?php

declare(strict_types=1);

namespace Preflow\Folio\Codes\Fields;

use Preflow\Codes\Purpose;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\Types\SelectFieldType;

/**
 * A code's purpose ("codes_purpose"): a select whose options are the purposes of
 * config/codes.php, so the model JSON needs no copy of them.
 */
final class PurposeFieldType implements FieldType, \Preflow\Folio\Field\HasColumnKind, \Preflow\Folio\Field\UsesFieldKinds
{
    use \Preflow\Folio\Field\Types\CoreValue;

    private readonly SelectFieldType $select;

    /** @param array<string, Purpose> $purposes */
    public function __construct(
        private readonly array $purposes,
    ) {
        $this->select = new SelectFieldType();
    }

    public function key(): string
    {
        return 'codes_purpose';
    }

    /** The core value of this type (used without kinds, or when they hold none for the key). */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new PurposeValue();
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Short;
    }

    public function renderEditor(FieldContext $ctx): string
    {
        return $this->select->renderEditor(new FieldContext(
            name: $ctx->name,
            label: $ctx->label,
            help: $ctx->help,
            value: $ctx->value,
            errors: $ctx->errors,
            config: $this->config($ctx->config),
            required: $ctx->required,
            placeholder: $ctx->placeholder,
            nestedErrors: $ctx->nestedErrors,
            locale: $ctx->locale,
            locales: $ctx->locales,
            record: $ctx->record,
            values: $ctx->values,
        ));
    }

    /** The posted key as a select takes it, as the core stores it (PurposeValue). */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->stored($this->select->normalizeInput($raw, $this->config($config)), $config);
    }

    public function toStorage(mixed $value): mixed
    {
        return $this->stored($value);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->output($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return $this->select->renderFrontend($value, $this->config($config));
    }

    /**
     * One of the purposes: the core's select check over them (a "codes_purpose" field is no
     * "select" to the core, so Folio brings this rule to it through FieldKinds).
     */
    public function rules(array $config): array
    {
        return \Preflow\Data\Validation\SelectRules::of(new \Preflow\Data\TypeFieldDefinition('purpose', 'select', config: $this->config($config)), []);
    }

    public function assets(): array
    {
        return $this->select->assets();
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function config(array $config): array
    {
        return ['options' => array_map(static fn (Purpose $p): string => $p->label, $this->purposes)] + $config;
    }
}
