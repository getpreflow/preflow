<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Preflow\Core\Http\Session\SessionInterface;

/**
 * The token a page prints (the request attribute CsrfMiddleware sets). A value object: after
 * SessionInterface::invalidate() in the same request the attribute still holds the removed
 * token; call generate() on the session for the new one.
 */
final class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    private function __construct(
        private readonly string $value,
        private readonly bool $stateless = false,
    ) {}

    public static function generate(SessionInterface $session): self
    {
        if (!$session->has(self::SESSION_KEY)) {
            $session->set(self::SESSION_KEY, bin2hex(random_bytes(32)));
        }
        return new self($session->get(self::SESSION_KEY));
    }

    public static function fromSession(SessionInterface $session): ?self
    {
        $value = $session->get(self::SESSION_KEY);
        return $value !== null ? new self($value) : null;
    }

    /** A signed token for a request without a session (see StatelessCsrfToken). */
    public static function stateless(string $value): self
    {
        return new self($value, true);
    }

    public function isStateless(): bool
    {
        return $this->stateless;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
