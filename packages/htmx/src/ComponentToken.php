<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Components\Component;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Exceptions\SecurityException;

/**
 * Signed component tokens (class, props, action, issue time). One instance issues all its
 * tokens at one time, the time of its first token: a page rendered twice in one request (the
 * page cache's verify) or again after the response (a rebuild) prints the same tokens, so it is
 * the same page for every visitor. The instance lives for one request (or one command), so the
 * age check of decode() is off by at most that long.
 */
class ComponentToken
{
    private ?int $issuedAt = null;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $algorithm = 'sha256',
    ) {}

    /**
     * @param array<string, mixed> $props
     */
    public function encode(
        string $componentClass,
        array $props = [],
        string $action = 'render',
    ): string {
        return $this->sign([
            'c' => $componentClass,
            'p' => $props,
            'a' => $action,
            't' => $this->issuedAt ??= $this->currentTime(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function sign(array $data): string
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac($this->algorithm, $payload, $this->secretKey);

        return sodium_bin2base64(
            $payload . '.' . $signature,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    /**
     * The token of $action on $component. A component with #[State] carries only its `key` (its
     * identity): the rest of its props were used by mount() and live on as its state, which the
     * page carries beside the token, and the token says so (TokenPayload::$stateCarried): the
     * endpoint then refuses a request without that state instead of mounting from no props. A
     * component without state carries all its props, as encode().
     *
     * @since 0.86.0
     */
    public function encodeFor(Component $component, string $action = 'render'): string
    {
        $props = $component->getProps();
        if ($component->hasState()) {
            $key = $props['key'] ?? null;
            $props = $key === null || $key === '' ? [] : ['key' => $key];

            return $this->sign([
                'c' => $component::class,
                'p' => $props,
                'a' => $action,
                't' => $this->issuedAt ??= $this->currentTime(),
                's' => 1,
            ]);
        }

        return $this->encode($component::class, $props, $action);
    }

    public function decode(string $token, ?int $maxAge = null): TokenPayload
    {
        try {
            $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new SecurityException('Invalid component token format.');
        }

        $lastDot = strrpos($decoded, '.');
        if ($lastDot === false) {
            throw new SecurityException('Invalid component token structure.');
        }

        $payload = substr($decoded, 0, $lastDot);
        $signature = substr($decoded, $lastDot + 1);

        $expected = hash_hmac($this->algorithm, $payload, $this->secretKey);

        if (!hash_equals($expected, $signature)) {
            throw new SecurityException('Invalid component token signature.');
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if ($maxAge !== null && ($this->currentTime() - ($data['t'] ?? 0)) > $maxAge) {
            throw new SecurityException('Component token expired.');
        }

        return new TokenPayload(
            componentClass: $data['c'],
            props: $data['p'] ?? [],
            action: $data['a'] ?? 'render',
            timestamp: $data['t'] ?? 0,
            stateCarried: ($data['s'] ?? 0) === 1,
        );
    }

    /** "Now" for issuing and for the age check: the current render's clock, the system clock outside every render. */
    protected function currentTime(): int
    {
        return Contexts::clock()->now()->getTimestamp();
    }
}
