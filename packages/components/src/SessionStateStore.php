<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\Http\Session\SessionInterface;

/**
 * Keeps the state in the session; the page carries a random reference. For large state behind a
 * login. The session holds the latest few states of each component (older open pages keep
 * working), under a key bound to class and id, and lives as long as the session does.
 *
 * @since 0.86.0
 */
final class SessionStateStore implements StateStore
{
    private const KEEP = 8;

    public function __construct(private readonly SessionInterface $session) {}

    public function put(string $componentClass, string $componentId, array $state): string
    {
        $key = $this->key($componentClass, $componentId);
        $kept = $this->session->get($key, []);
        $kept = is_array($kept) ? $kept : [];

        $ref = sodium_bin2base64(random_bytes(16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $kept[$ref] = $state;
        $this->session->set($key, array_slice($kept, -self::KEEP, null, true));

        return $ref;
    }

    public function get(string $componentClass, string $componentId, string $carried): array
    {
        $kept = $this->session->get($this->key($componentClass, $componentId), []);

        if (!is_array($kept) || !isset($kept[$carried]) || !is_array($kept[$carried])) {
            throw new StateRefused('Component state is unknown or expired.');
        }

        return $kept[$carried];
    }

    private function key(string $componentClass, string $componentId): string
    {
        return 'preflow.state.' . hash('sha256', $componentClass . "\0" . $componentId);
    }
}
