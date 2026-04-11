<?php

declare(strict_types=1);

namespace Preflow\Data\Transform;

use Preflow\Data\FieldTransformer;

final class JsonTransformer implements FieldTransformer
{
    public function toStorage(mixed $value): mixed
    {
        if ($value === null) { return null; }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function fromStorage(mixed $value): mixed
    {
        if ($value === null || $value === '') { return null; }
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
