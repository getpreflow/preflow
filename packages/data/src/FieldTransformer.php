<?php

declare(strict_types=1);

namespace Preflow\Data;

interface FieldTransformer
{
    public function toStorage(mixed $value): mixed;
    public function fromStorage(mixed $value): mixed;
}
