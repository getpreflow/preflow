<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

/**
 * The template global `interface_locale`: the interface language of the render that prints it
 * (InterfaceLocales::current()), read each time it is printed or compared, so one engine global
 * serves every render (an admin page, a public page, a mail) without being set per request.
 *
 * @internal Folio's engine global
 * @since 0.81.0
 */
final readonly class InterfaceLocale implements \Stringable, \JsonSerializable
{
    /** @param \Closure(): InterfaceLocales $locales */
    public function __construct(private \Closure $locales) {}

    public function __toString(): string
    {
        return ($this->locales)()->current();
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
