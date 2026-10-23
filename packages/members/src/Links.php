<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\Locales;

/**
 * Absolute links for mail, from config app.url, never from the request Host: the member
 * pages under {@see MembersConfig::path()}, prefixed with the locale as config/i18n.php
 * does it (the default locale at the root).
 */
final class Links
{
    private readonly string $base;

    /** @param bool $prefixed whether the URLs carry the locale (url_strategy "prefix") */
    public function __construct(
        string $baseUrl,
        private readonly string $path,
        private readonly Locales $locales,
        private readonly bool $prefixed = true,
        private readonly string $folioPrefix = '/folio',
    ) {
        $this->base = rtrim($baseUrl, '/');
    }

    /** A member page in $locale (an unknown locale gets the default), e.g. url('de', '/login'). */
    public function url(string $locale, string $path = ''): string
    {
        $locale = $this->locales->has($locale) ? $locale : $this->locales->default;
        $prefix = $this->prefixed && $locale !== $this->locales->default ? '/' . $locale : '';

        return $this->base . $prefix . $this->path . $path;
    }

    /** The member's edit page in Folio. */
    public function folioMember(string $id): string
    {
        return $this->base . rtrim($this->folioPrefix, '/') . '/' . MembersConfig::TYPE . '/' . rawurlencode($id) . '/edit';
    }
}
