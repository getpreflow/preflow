<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * A field whose submitted value is merged with its stored value (nested,
 * per-locale data, nested uploads). DraftRecordFactory calls buildValue()
 * instead of normalizeInput().
 */
interface StructuredFieldType
{
    /**
     * @param mixed                $input    the submitted value for this field
     * @param mixed                $existing the stored value (raw)
     * @param array<mixed>         $files    uploaded files under this field's name
     * @param array<string, mixed> $config   the field's config (e.g. blocks "allowed")
     */
    public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx, array $config = []): mixed;
}
