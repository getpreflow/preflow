<?php
declare(strict_types=1);
namespace Preflow\View;

final class NonceGenerator
{
    private ?string $nonce = null;

    public function get(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }
}
