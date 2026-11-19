<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * The one check every write asks before it touches a store (DataManager's write entry points,
 * the redirect hit counters and not-found log, media variant files, the AI usage log):
 *
 *     if (!Writes::allowed($storage, $type, $id, 'save')) {
 *         return; // skipped: return as if written
 *     }
 *
 * Allow, or no render at all (a script, a unit test; ruling R1): true. Skip (a preview, a view
 * as a role, the SEO audit): the write is recorded in the current frame as a SkippedWrite and
 * false comes back, so the caller returns as if it had written. Refuse (a fragment under a
 * preview token): WritesRefused is thrown, except for 'log' writes (a redirect hit, the
 * not-found log, a usage line), which are skipped and reported, so a preview that follows an
 * old address still redirects.
 *
 * Ask before the write, never inside a storage driver: every driver then follows the policy.
 *
 * @since 0.81.0
 */
final class Writes
{
    /**
     * @param string $storage the storage (or store) written to
     * @param string $type the record type, table, file kind or log
     * @param string|null $id the record's id, when the write names one
     * @param string $operation save, delete, raw, increment, file or log (SkippedWrite)
     *
     * @throws WritesRefused under WritePolicy::Refuse for anything but a 'log' write
     */
    public static function allowed(string $storage, string $type, ?string $id, string $operation): bool
    {
        $policy = self::policy();
        if ($policy === WritePolicy::Allow) {
            return true;
        }
        $write = new SkippedWrite($storage, $type, $id, $operation);
        if ($policy === WritePolicy::Refuse && $operation !== 'log') {
            throw new WritesRefused($write);
        }
        Contexts::frame()->recordSkippedWrite($write);

        return false;
    }

    /**
     * The write policy of the current render; Allow outside every render. For a caller that
     * must first work out whether it writes at all (raw SQL is classified only when writes are
     * not simply allowed).
     */
    public static function policy(): WritePolicy
    {
        return Contexts::peek()?->writes ?? WritePolicy::Allow;
    }
}
