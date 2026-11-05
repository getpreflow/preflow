<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * Thrown by AuditRun::run() when another audit of the same site is running (it
 * holds the report's lock); the run did nothing. Its message says so in a way
 * the command can print as it is.
 *
 * @since 0.54
 */
final class AuditRunning extends \RuntimeException
{
    public static function at(string $file): self
    {
        return new self("Another SEO audit is running on this site (it holds {$file}.lock). Wait until it has finished, then run the audit again.");
    }
}
