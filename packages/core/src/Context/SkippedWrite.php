<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * A data write a render tried and that was skipped (WritePolicy::Skip), reported to the caller:
 * the SEO audit lists them per page, a preview shows them.
 *
 * @since 0.81.0
 */
final readonly class SkippedWrite
{
    private const OPERATIONS = ['save', 'delete', 'raw', 'increment', 'file', 'log'];

    /**
     * @param string $storage the storage it was meant for
     * @param string $type the record type (or table, file, log) written to
     * @param string|null $id the record's id, when the write names one
     * @param string $operation save, delete, raw, increment, file or log
     *
     * @throws \InvalidArgumentException for any other operation
     */
    public function __construct(
        public string $storage,
        public string $type,
        public ?string $id,
        public string $operation,
    ) {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException("A skipped write is one of " . implode(', ', self::OPERATIONS) . ", got '{$operation}'.");
        }
    }
}
