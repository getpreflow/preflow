<?php

declare(strict_types=1);

namespace Preflow\Codes\Fields;

use Preflow\Codes\Purpose;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\Types\SelectFieldType;

/**
 * A code's purpose ("codes_purpose"): a select whose options are the purposes of
 * config/codes.php, so the model JSON needs no copy of them.
 */
final class PurposeFieldType implements FieldType
{
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
        ));
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->select->normalizeInput($raw, $this->config($config));
    }

    public function toStorage(mixed $value): mixed
    {
        return $this->select->toStorage($value);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->select->fromStorage($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return $this->select->renderFrontend($value, $this->config($config));
    }

    public function rules(array $config): array
    {
        return $this->select->rules($this->config($config));
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
