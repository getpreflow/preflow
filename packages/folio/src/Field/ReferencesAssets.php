<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * A field type whose stored value holds ids of media library assets (an asset field's files, an
 * embed's poster, a link to a file). Folio's asset walker (Assets\AssetReferences) asks it, so
 * those files count as used: the library refuses to delete them while a record holds them, and
 * cleanup never bins them. A field type that holds asset ids and does not implement this has its
 * files treated as unused.
 *
 * @since 0.91.0
 */
interface ReferencesAssets
{
    /**
     * $value with every asset id it holds replaced by $fn(id), in the same shape (JSON text stays
     * JSON text, an empty or foreign value stays as it is). The walker collects the ids with an $fn
     * that records its argument and returns it unchanged.
     *
     * @param mixed                    $value  the stored value (as storage gives it: an array or JSON text)
     * @param array<string, mixed>     $config the field's config
     * @param \Closure(string): string $fn
     */
    public function mapAssetIds(mixed $value, array $config, \Closure $fn): mixed;
}
