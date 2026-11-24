<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * Where the state of one rendered component is kept. put() returns the opaque string the page
 * carries; get() turns that string back into the state or refuses it.
 *
 * @since 0.86.0
 */
interface StateStore
{
    /**
     * @param array<string, mixed> $state plain values only
     * @return string what the page carries
     */
    public function put(string $componentClass, string $componentId, array $state): string;

    /**
     * @return array<string, mixed>
     * @throws StateRefused for tampered, foreign, expired or unreadable input
     */
    public function get(string $componentClass, string $componentId, string $carried): array;
}
