<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Members\Links;
use Preflow\Members\MembersConfig;

/**
 * Where a sign-in, or a registration once confirmed, may send the member ("next"): the member
 * pages of this site (under {@see MembersConfig::path()}) and the paths config/members.php
 * next.allow lists ({@see MembersConfig::nextAllow()}), each in any locale; never another host,
 * a scheme, a double slash, a backslash or a dot segment, also percent-encoded. Else the landing.
 */
final class SafeNext
{
    public function __construct(
        private readonly MembersConfig $config,
        private readonly Links $links,
    ) {}

    /** $next when it is a member page or an allowed path, else the landing in $locale. */
    public function of(?string $next, string $locale): string
    {
        $home = $this->links->localize($locale, $this->config->landing());
        if ($next === null || $next === '') {
            return $home;
        }
        // Browsers decode %2e and friends before resolving dot segments: judge every decoded form, down to the fixed point.
        $seen = $next;
        for ($i = 0; $i < 5; $i++) {
            if (str_contains($seen, '..') || str_contains($seen, '//') || str_contains($seen, '\\') || preg_match('/[^\x21-\x7E]/', $seen) === 1) {
                return $home;
            }
            $decoded = rawurldecode($seen);
            if ($decoded === $seen) {
                break;
            }
            $seen = $decoded;
        }
        if ($seen !== rawurldecode($seen)) {
            return $home;
        }

        return preg_match('#^(?:' . $this->bases() . ')(/[A-Za-z0-9._~%/-]*)?(\?[\x21-\x7E]*)?$#D', $next) === 1 ? $next : $home;
    }

    /**
     * "?next=..." to pass $next on to another member page (the login and register links, the
     * redirect to a site's own register page) when this class keeps it; else "".
     */
    public function query(?string $next, string $locale): string
    {
        return $next !== null && $next !== '' && $this->of($next, $locale) === $next ? '?next=' . rawurlencode($next) : '';
    }

    /** The members path and the allowed paths in every locale ("/members|/de/members|/games"), as a regex alternation. */
    private function bases(): string
    {
        $bases = [];
        foreach ($this->links->locales() as $locale) {
            $bases[] = preg_quote($this->links->local($locale), '#');
            foreach ($this->config->nextAllow() as $path) {
                $bases[] = preg_quote($this->links->localize($locale, rtrim($path, '/')), '#');
            }
        }

        return implode('|', array_unique($bases));
    }
}
