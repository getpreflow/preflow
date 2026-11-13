<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Core\Http\SafeReturnPath;
use Preflow\Members\Links;
use Preflow\Members\MembersConfig;

/**
 * Where a sign-in, or a registration once confirmed, may send the member ("next"): the member
 * pages of this site (under {@see MembersConfig::path()}) and the paths config/members.php
 * next.allow lists ({@see MembersConfig::nextAllow()}), each in any locale; never another host,
 * a scheme, a double slash, a backslash or a dot segment, also percent-encoded ({@see SafeReturnPath}).
 * Else the landing.
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

        return SafeReturnPath::check($next, $this->bases()) ?? $home;
    }

    /**
     * "?next=..." to pass $next on to another member page (the login and register links, the
     * redirect to a site's own register page) when this class keeps it; else "".
     */
    public function query(?string $next, string $locale): string
    {
        return $next !== null && $next !== '' && $this->of($next, $locale) === $next ? '?next=' . rawurlencode($next) : '';
    }

    /**
     * The members path and the allowed paths in every locale ("/members", "/de/members", "/games").
     *
     * @return list<string>
     */
    private function bases(): array
    {
        $bases = [];
        foreach ($this->links->locales() as $locale) {
            $bases[] = $this->links->local($locale);
            foreach ($this->config->nextAllow() as $path) {
                $bases[] = $this->links->localize($locale, $path);
            }
        }

        return array_values(array_unique($bases));
    }
}
