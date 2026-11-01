<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

/**
 * Signed, session-less CSRF token: v1.{slot}.{base64url(hmac)}.
 * Not tied to a visitor; valid in the slot it was issued and the next one.
 */
final class StatelessCsrfToken
{
    /** The key Application falls back to; it is public, so it must not sign anything. */
    public const DEFAULT_KEY = 'preflow-default-key-change-me!!';

    private const SLOT_SECONDS = 86400;
    private const PURPOSE = 'preflow.csrf';
    private const PATTERN = '/^v1\.\d{1,12}\.[A-Za-z0-9_-]{43}$/';

    private readonly \Closure $clock;

    public function __construct(private readonly string $key, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function enabled(): bool
    {
        return $this->key !== '' && $this->key !== self::DEFAULT_KEY;
    }

    public function issue(): string
    {
        if (!$this->enabled()) {
            throw new \LogicException('Stateless CSRF tokens need a non-default app.key.');
        }

        $slot = $this->slot();

        return 'v1.' . $slot . '.' . $this->sign($slot);
    }

    public function verify(string $token): bool
    {
        if (!$this->enabled() || !self::looksStateless($token)) {
            return false;
        }

        [, $slot, $mac] = explode('.', $token);
        $slot = (int) $slot;
        $current = $this->slot();

        if ($slot !== $current && $slot !== $current - 1) {
            return false;
        }

        return hash_equals($this->sign($slot), $mac);
    }

    /** Tells a stateless token from a session token (64 hex characters) by its format. */
    public static function looksStateless(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }

    private function slot(): int
    {
        return intdiv(($this->clock)(), self::SLOT_SECONDS);
    }

    private function sign(int $slot): string
    {
        $mac = hash_hmac('sha256', 'v1|' . $slot . '|' . self::PURPOSE, $this->key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
