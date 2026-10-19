<?php

declare(strict_types=1);

namespace Preflow\DevTools;

/**
 * Finds markup in a rendered page that the strict CSP will block in
 * production: debug mode sends the policy as report-only, so the debug
 * toolbar lists these instead of letting them surface only after deploy.
 */
final class CspInspector
{
    /** @return list<string> one human-readable issue per offending tag */
    public static function issues(string $html, ?string $nonce): array
    {
        $issues = [];

        preg_match_all('/<(script|style)\b([^>]*)>/i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $tag, $attrs]) {
            $tag = strtolower($tag);
            if ($tag === 'script' && preg_match('/\bsrc\s*=|type\s*=\s*["\']application\/(ld\+)?json/i', $attrs) === 1) {
                continue;
            }
            $hasNonce = preg_match('/\bnonce\s*=\s*["\']([^"\']*)["\']/i', $attrs, $m) === 1
                && ($nonce === null || $m[1] === $nonce);
            if (!$hasNonce) {
                $issues[] = "{$tag} without nonce";
            }
        }

        preg_match_all('/<[a-z][^>]*\s(on[a-z]+)\s*=/i', $html, $handlers);
        foreach ($handlers[1] as $handler) {
            $issues[] = 'inline event handler (' . strtolower($handler) . ')';
        }

        $styleAttributes = preg_match_all('/<[a-z][^>]*\sstyle\s*=/i', $html);
        for ($i = 0; $i < $styleAttributes; $i++) {
            $issues[] = 'style attribute';
        }

        return $issues;
    }
}
