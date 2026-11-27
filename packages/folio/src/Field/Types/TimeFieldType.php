<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\Values\TimeOfDay;
use Preflow\Folio\Field\DisplaysInAdmin;

/**
 * A time of day (0.91; the value and check are preflow/fields': Values\TimeOfDay, ValidTime): a
 * wall-clock "HH:MM" with no date and no zone ("opens at 09:00"). The browser's time input;
 * "9:05" and "09:05:30" are read as "09:05". Anything else stays as typed for the check to
 * refuse. Empty is null.
 */
final class TimeFieldType extends AbstractScalarFieldType implements DisplaysInAdmin
{
    public function key(): string
    {
        return 'time';
    }

    /** The core value of this type (used without kinds, or when they hold none for the key). */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new \Preflow\Fields\Values\Field\TimeValue();
    }

    protected function inputType(): string
    {
        return 'time';
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $this->stored($raw, $config);
    }

    /** "HH:MM" as the core stores it; text that is no time is kept as typed, so the editor shows it and the check refuses it. */
    public function fromStorage(mixed $value): mixed
    {
        return $this->stored($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $time = TimeOfDay::fromStored($value);

        return $time === null ? '' : '<time>' . $time->toStored() . '</time>';
    }

    /** @param array<string, mixed> $config */
    public function renderAdmin(mixed $value, array $config): string
    {
        return self::escaper()(TimeOfDay::fromStored($value)?->toStored() ?? (is_string($value) ? $value : ''));
    }

    public function rules(array $config): array
    {
        return [];
    }
}
