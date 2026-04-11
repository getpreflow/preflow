<?php

declare(strict_types=1);

namespace Preflow\Data\Transform;

use Preflow\Data\FieldTransformer;

final class DateTimeTransformer implements FieldTransformer
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function toStorage(mixed $value): mixed
    {
        if ($value === null) { return null; }
        if ($value instanceof \DateTimeInterface) { return $value->format(self::FORMAT); }
        return $value;
    }

    public function fromStorage(mixed $value): mixed
    {
        if ($value === null || $value === '') { return null; }
        $dt = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);
        if ($dt === false) { $dt = new \DateTimeImmutable($value); }
        return $dt;
    }
}
