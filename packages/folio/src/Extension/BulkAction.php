<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A button in the list's bulk bar, at POST {prefix}/{type}/_bulk/{name} with the ticked ids[],
 * for those with the permission (and the type's edit permission). The method is
 * fn(list<DynamicRecord> $records, ServerRequestInterface $request): ?ResponseInterface;
 * the records are the ticked ones of this type that exist, in the order posted. null
 * redirects back to the list (same search, filters, sort and page) with "{label}: done (N)".
 * The names "delete", "export" and "set-…" are Folio's own.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class BulkAction
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $permission,
        public readonly ?string $confirm = null,
    ) {}
}
