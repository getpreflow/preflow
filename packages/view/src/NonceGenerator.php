<?php
declare(strict_types=1);
namespace Preflow\View;

final class NonceGenerator
{
    private ?string $nonce = null;

    /** The request's own nonce while an adopted one is in use (adopted() is then true). */
    private ?string $own = null;

    private bool $adopted = false;

    public function get(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Answer this request with $nonce, the nonce of the page a fragment request came from (a
     * hypermedia client sends it back: HypermediaProtocol::pageNonce()), so the scripts and
     * styles the response puts into that page pass its Content-Security-Policy. It is untrusted
     * client input: core adopts it for a fragment request only, refuses to store that response
     * in the page cache, and calls release() when the request is done. A value that is no
     * base64 nonce of at least 22 characters (16 random bytes) is ignored (false).
     */
    public function adopt(string $nonce): bool
    {
        if (preg_match('#^[A-Za-z0-9+/_-]{22,64}={0,2}$#', $nonce) !== 1) {
            return false;
        }
        if (!$this->adopted) {
            $this->own = $this->nonce;
            $this->adopted = true;
        }
        $this->nonce = $nonce;

        return true;
    }

    /** Whether the nonce in use is an adopted one (adopt()), not this request's own. */
    public function adopted(): bool
    {
        return $this->adopted;
    }

    /** Back to the request's own nonce after adopt() (a fresh one if none was handed out). */
    public function release(): void
    {
        if ($this->adopted) {
            $this->nonce = $this->own;
            $this->own = null;
            $this->adopted = false;
        }
    }
}
