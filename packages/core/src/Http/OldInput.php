<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Http\Session\SessionInterface;

/**
 * Posted fields kept for the next request as a flash, so a form sent back (after an expired
 * session) shows them again. Validation's old(), the form builder and members read it.
 * Never kept: the CSRF token, any field whose name contains "password", uploaded files.
 */
final class OldInput
{
    public const string FLASH_KEY = '_old_input';

    /** @param \Closure(): ?SessionInterface $session the current request's session, if any */
    public function __construct(private readonly \Closure $session) {}

    /** @param array<array-key, mixed> $parsedBody */
    public static function keep(SessionInterface $session, array $parsedBody): void
    {
        unset($parsedBody['_csrf_token']);
        $kept = self::filter($parsedBody);
        if ($kept !== []) {
            $session->flash(self::FLASH_KEY, $kept);
        }
    }

    /** @return array<array-key, mixed> */
    public function all(): array
    {
        $session = ($this->session)();
        if (!$session instanceof SessionInterface || !$session->isStarted()) {
            return [];
        }
        $old = $session->getFlash(self::FLASH_KEY, []);

        return is_array($old) ? $old : [];
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->all()[$field] ?? $default;
    }

    /**
     * Scalars and nested arrays only (a file or other object never reaches the session);
     * an array that held nothing else to keep is dropped.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private static function filter(array $input): array
    {
        $kept = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && stripos($key, 'password') !== false) {
                continue;
            }
            if (is_array($value)) {
                $value = self::filter($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value !== null && !is_scalar($value)) {
                continue;
            }
            $kept[$key] = $value;
        }

        return $kept;
    }
}
