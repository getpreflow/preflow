<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Preflow\Core\Http\Session\SessionInterface;

final class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    private function __construct(private readonly string $value) {}

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

    public function getValue(): string
    {
        return $this->value;
    }
}
