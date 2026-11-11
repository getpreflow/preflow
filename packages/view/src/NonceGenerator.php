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

    /**
     * Answer this request with $nonce, the nonce of the page a fragment request came from (a
     * hypermedia client sends it back: HypermediaProtocol::pageNonce()), so the scripts and
     * styles the response puts into that page pass its Content-Security-Policy. Core calls it
     * before the request is handled. A value that is no base64 nonce is ignored (false).
     */
    public function adopt(string $nonce): bool
    {
        if (preg_match('#^[A-Za-z0-9+/_-]{16,64}={0,2}$#', $nonce) !== 1) {
            return false;
        }
        $this->nonce = $nonce;

        return true;
    }
}
