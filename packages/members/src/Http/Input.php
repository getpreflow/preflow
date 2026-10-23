<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\Locales;
use Psr\Http\Message\ServerRequestInterface;

/** What the member controllers read from a request: form fields, the locale, flash notices. */
final class Input
{
    /** The flash key of the notice the next page shows (e.g. "Your account is deleted." on the register page). */
    public const string NOTICE = 'members.notice';

    /** The flash key of the account page's notice ("Password changed."). */
    public const string ACCOUNT_NOTICE = 'members.account';

    /** A posted (or query) field as a string; an array or a missing field is ''. @param array<mixed> $body */
    public static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @return array<mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }

    /** The request's locale (the locale middleware sets it), the default when it is none of the site's. */
    public static function locale(ServerRequestInterface $request, Locales $locales): string
    {
        $locale = $request->getAttribute('locale');

        return is_string($locale) && $locales->has($locale) ? $locale : $locales->default;
    }

    /** A flash notice set by the previous request ('' without a session or notice). */
    public static function flash(ServerRequestInterface $request, string $key): string
    {
        $session = $request->getAttribute(SessionInterface::class);
        $value = $session instanceof SessionInterface ? $session->getFlash($key) : null;

        return is_string($value) ? $value : '';
    }

    public static function flashSet(ServerRequestInterface $request, string $key, string $text): void
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash($key, $text);
        }
    }
}
