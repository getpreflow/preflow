<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Why a render happens. Code that must behave differently for a visitor's page and for an
 * internal render (the SEO audit, a cache rebuild) asks the purpose, never the request.
 *
 * @since 0.81.0
 */
enum Purpose: string
{
    /** A visitor's request for a whole page. */
    case Visit = 'visit';
    /** A request whose answer goes into a page already shown (a component action, an htmx swap). */
    case Fragment = 'fragment';
    /** The SEO audit renders a page to check it. */
    case Audit = 'audit';
    /** The GEO export renders a page to describe it. */
    case Geo = 'geo';
    /** The page cache renders a page again to store it. */
    case Rebuild = 'rebuild';
    /** A check that a page renders (a redirect target, a link check). */
    case Verify = 'verify';
    /** An editor previews a page (a working copy, a role). */
    case Preview = 'preview';
    /** A console command. */
    case Console = 'console';
    /** A queued or scheduled job. */
    case Job = 'job';
    /** A mail being composed. */
    case Mail = 'mail';
    /** A PDF being rendered. */
    case Pdf = 'pdf';
    /** A test. */
    case Test = 'test';

    /** The render must leave nothing behind: no log line, counter or stored state. */
    public function leavesNoTrace(): bool
    {
        return match ($this) {
            self::Audit, self::Geo, self::Rebuild, self::Preview => true,
            default => false,
        };
    }

    /** The application renders for itself, not for the request it was asked by (the superglobal adapter applies). */
    public function isInternal(): bool
    {
        return match ($this) {
            self::Audit, self::Geo, self::Rebuild, self::Verify, self::Preview => true,
            default => false,
        };
    }

    /** The page cache may store what this render produces (when the context is the public default too). */
    public function isStorable(): bool
    {
        return match ($this) {
            self::Visit, self::Rebuild => true,
            default => false,
        };
    }
}
