<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Which texts a render shows: the visitor's (public), or the admin's in its interface language,
 * with the site's translation overrides on or off.
 *
 * @since 0.81.0
 */
final readonly class Surface
{
    private function __construct(
        public bool $admin,
        public ?string $uiLocale,
        public bool $overrides,
    ) {}

    /** The visitor's texts (no interface locale, overrides off, as the translator's default). */
    public static function public(): self
    {
        return new self(false, null, false);
    }

    /** The admin's texts in $uiLocale, with the site's translation overrides unless $overrides is false. */
    public static function admin(string $uiLocale, bool $overrides = true): self
    {
        return new self(true, $uiLocale, $overrides);
    }

    /** 'public', or 'admin:{uiLocale}:overrides' / 'admin:{uiLocale}:plain'. */
    public function key(): string
    {
        return $this->admin ? 'admin:' . $this->uiLocale . ':' . ($this->overrides ? 'overrides' : 'plain') : 'public';
    }
}
