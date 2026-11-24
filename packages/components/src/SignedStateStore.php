<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\Context\Contexts;

/**
 * The default store: the page carries the state itself, signed with the app key and bound to
 * component class and id. One instance issues all its strings at one time (its first), so equal
 * state prints equal strings within a request and a cached page stays byte-equal.
 *
 * @since 0.86.0
 */
class SignedStateStore implements StateStore
{
    private ?int $issuedAt = null;

    public function __construct(
        private readonly string $secretKey,
        private readonly int $maxAge = 86400,
    ) {}

    public function put(string $componentClass, string $componentId, array $state): string
    {
        $payload = json_encode([
            'c' => $componentClass,
            'i' => $componentId,
            's' => $state,
            't' => $this->issuedAt ??= $this->currentTime(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return sodium_bin2base64(
            $payload . '.' . hash_hmac('sha256', $payload, $this->secretKey),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    public function get(string $componentClass, string $componentId, string $carried): array
    {
        try {
            $decoded = sodium_base642bin($carried, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $e) {
            throw new StateRefused('Component state is not readable.', $e);
        }

        $dot = strrpos($decoded, '.');
        if ($dot === false) {
            throw new StateRefused('Component state is not readable.');
        }

        $payload = substr($decoded, 0, $dot);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secretKey), substr($decoded, $dot + 1))) {
            throw new StateRefused('Component state signature is invalid.');
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StateRefused('Component state is not readable.', $e);
        }

        if (!is_array($data) || !isset($data['c'], $data['i'], $data['t']) || !is_array($data['s'] ?? null)) {
            throw new StateRefused('Component state is not readable.');
        }

        if ($data['c'] !== $componentClass || $data['i'] !== $componentId) {
            throw new StateRefused('Component state belongs to another component.');
        }

        if (!is_int($data['t']) || ($this->currentTime() - $data['t']) > $this->maxAge) {
            throw new StateRefused('Component state expired.');
        }

        return $data['s'];
    }

    /** "Now" for issuing and for the age check: the current render's clock, the system clock outside every render. */
    protected function currentTime(): int
    {
        return Contexts::clock()->now()->getTimestamp();
    }
}
