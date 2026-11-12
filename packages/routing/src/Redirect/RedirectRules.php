<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/**
 * The rules a redirect entry has to meet, whoever writes it (a form, a command, an import): no
 * chains (no entry may answer where another sends visitors), a page wins over an exact entry,
 * no area over the whole site, a target is never inside its own area, a record target is live,
 * "keep the rest" needs a URL target without query or fragment. What only the site knows (is a
 * page there, is a record live, how addresses read) comes in through small interfaces.
 *
 * The rules hold for every write that goes through them (the store's save() checks each entry
 * with check()), so they have to be wired into the store; a store written to around them is not
 * held to them.
 *
 * An entry is an array: path (RedirectPath's form), locale ('' any), area, mode, and one of
 * target_type + target_id or target_url. $existing is the stored entry being edited (an array
 * with its id): the rules leave it out when they look at the other entries. Errors are message
 * keys per field ('address', 'target'); the writer maps them to its own texts.
 */
final class RedirectRules
{
    public function __construct(
        private readonly ListedRedirectStore $store,
        private readonly PageExists $pages,
        private readonly TargetIsLive $live,
        private readonly SiteAddresses $site,
        private readonly ?NotFoundList $notFound = null,
    ) {}

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $existing
     * @param string $host the request's host, for absolute URLs of this site ('' for none)
     */
    public function check(array $entry, ?array $existing, string $host = ''): RedirectErrors
    {
        return $this->address($entry, $existing, $host)->merge($this->target($entry, $existing, $host));
    }

    /**
     * The address's rules: a normalized path, not an area over the whole site, not an exact
     * entry where a page answers, not taken by another entry, not where another entry sends
     * visitors.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $existing
     */
    public function address(array $entry, ?array $existing, string $host = ''): RedirectErrors
    {
        $path = (string) ($entry['path'] ?? '');
        $locale = (string) ($entry['locale'] ?? '');
        $area = (bool) ($entry['area'] ?? false);
        $own = isset($existing['id']) ? (string) $existing['id'] : null;

        if ($path === '' || RedirectPath::normalize($path) !== $path) {
            return self::refuse('address', 'address_invalid');
        }
        // RedirectPath::under() never matches '/', and an area over the whole site would hide every 404.
        if ($area && $path === '/') {
            return self::refuse('address', 'whole_site');
        }
        // An exact entry at a page's address would never answer; an area's root may be a page (it covers what is below).
        if (!$area && $this->pages->exists($this->site->lookupLocale($locale), $path)) {
            return self::refuse('address', 'page_wins');
        }
        $taken = $this->store->entry(Redirect::id($area ? 'area' : 'exact', $locale, $path));
        if ($taken !== null && $taken->id !== $own) {
            return self::refuse('address', 'taken');
        }
        // No chains: other entries that send visitors here would send them on again.
        if ($this->targetedBy($locale, $path, $area, $own, $host)) {
            return self::refuse('address', 'targeted');
        }

        return new RedirectErrors();
    }

    /**
     * The target's rules: there is one (a live record, or an http(s) URL or a path on this site,
     * never "//host" or "\\host"), never the address itself, an address inside the area, or an
     * address another entry redirects (no chains); "keep the rest" takes no record and no ? or #
     * (the rest of the path is appended after the target). With no path yet (the address was
     * refused) only the rules that do not need it run.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $existing
     */
    public function target(array $entry, ?array $existing, string $host = ''): RedirectErrors
    {
        $path = (string) ($entry['path'] ?? '');
        $locale = (string) ($entry['locale'] ?? '');
        $area = (bool) ($entry['area'] ?? false);
        $mode = $area ? (string) ($entry['mode'] ?? AreaMode::One->value) : AreaMode::One->value;
        $prefix = $mode === AreaMode::Prefix->value;
        $own = isset($existing['id']) ? (string) $existing['id'] : null;
        $type = self::optional($entry['target_type'] ?? null);
        $id = self::optional($entry['target_id'] ?? null);
        $url = self::optional($entry['target_url'] ?? null);

        if ($type !== null && $id !== null) {
            if ($prefix) {
                return self::refuse('target', 'prefix_needs_url');
            }

            return $this->live->isLive($type, $id) ? new RedirectErrors() : self::refuse('target', 'target_not_live');
        }
        if ($url === null) {
            return self::refuse('target', 'target_required');
        }
        if (!RedirectUrl::isValid($url)) {
            return self::refuse('target', 'target_invalid');
        }
        if ($prefix && (str_contains($url, '?') || str_contains($url, '#'))) {
            return self::refuse('target', 'prefix_no_query');
        }
        $at = $this->site->address($url, $host);
        if ($at === null || $path === '') {
            return new RedirectErrors();
        }
        [$targetLocale, $targetPath] = $at;
        $sameLanguage = $locale === '' || $targetLocale === $locale;
        if ($sameLanguage && $targetPath === $path) {
            return self::refuse('target', 'target_is_address');
        }
        // /blog → /blog/archive would run /blog/x → /blog/archive/x → /blog/archive/archive/x …
        if ($area && $sameLanguage && RedirectPath::under($targetPath, $path) !== null) {
            return self::refuse('target', 'target_in_area');
        }
        if ($this->redirectsAt($this->site->lookupLocale($targetLocale), $targetPath, $own)) {
            return self::refuse('target', 'target_redirects');
        }
        // "Keep the rest" sends visitors below the target too: no entry may answer there.
        if ($prefix && $this->entryBelow($targetLocale, $targetPath, $own)) {
            return self::refuse('target', 'target_redirects');
        }

        return new RedirectErrors();
    }

    /**
     * After an entry is stored: its address leaves the not-found list (an area takes everything
     * below it along).
     *
     * @param array<string, mixed> $entry the stored entry
     */
    public function saved(array $entry): void
    {
        if ($this->notFound === null) {
            return;
        }
        $locale = (string) ($entry['locale'] ?? '');
        $path = (string) ($entry['path'] ?? '');
        if ((bool) ($entry['area'] ?? false)) {
            $this->notFound->forgetUnder($locale, $path);
        } else {
            $this->notFound->forget($locale, $path);
        }
    }

    /**
     * Whether another entry's URL target lies at this address (exact) or under it (area), in its
     * language, where no page answers. A "keep the rest" area sends visitors to its target and
     * everything below it: an address there counts too, and so does an area being saved that
     * holds the target.
     */
    private function targetedBy(string $locale, string $path, bool $area, ?string $own, string $host): bool
    {
        foreach ($this->store->entries() as $other) {
            if ($other->id === $own || $other->targetUrl === null) {
                continue;
            }
            $at = $this->site->address($other->targetUrl, $host);
            if ($at === null || ($locale !== '' && $at[0] !== $locale)) {
                continue;
            }
            if ($other->area && $other->mode === AreaMode::Prefix) {
                if (RedirectPath::under($path, $at[1]) !== null || ($area && RedirectPath::under($at[1], $path) !== null)) {
                    return true;
                }
                continue;
            }
            $hit = $area ? RedirectPath::under($at[1], $path) !== null : $at[1] === $path;
            if ($hit && !$this->pages->exists($this->site->lookupLocale($at[0]), $at[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a request for the address would be redirected, as the middleware looks it up (an
     * exact entry, else an area above it), leaving out the entry being edited. A page there
     * answers first: no redirect.
     */
    private function redirectsAt(string $locale, string $path, ?string $own): bool
    {
        $exact = $this->store->exact($path, $locale);
        $redirected = $exact !== null && $exact->id !== $own;
        if (!$redirected) {
            foreach ($this->store->areas($locale) as $area) {
                if ($area->id !== $own && RedirectPath::under($path, $area->path) !== null) {
                    $redirected = true;
                    break;
                }
            }
        }

        return $redirected && !$this->pages->exists($locale, $path);
    }

    /** Whether an entry (exact or area) other than $own lies below the path, in its language. */
    private function entryBelow(string $locale, string $path, ?string $own): bool
    {
        foreach ($this->store->entries() as $entry) {
            if ($entry->id !== $own
                && ($locale === '' || $entry->locale === '' || $entry->locale === $locale)
                && !in_array(RedirectPath::under($entry->path, $path), [null, ''], true)) {
                return true;
            }
        }

        return false;
    }

    private static function refuse(string $field, string $key): RedirectErrors
    {
        return new RedirectErrors([$field => $key]);
    }

    /** '' and null are "none". */
    private static function optional(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
