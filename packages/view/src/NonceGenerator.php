<?php

declare(strict_types=1);

namespace Preflow\View;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderFrame;

/**
 * The CSP nonce of the render in progress. Since 0.81 each render frame has its own (a frame
 * slot): the outermost frame of a request and every render the app makes of itself (the SEO
 * audit, GEO, a page cache rebuild, verify, a preview) get one of their own, made on first use;
 * a request a page asks for in-process shares the nonce of the frame around it (share()), unless
 * it is a fragment request, which answers its own page. Contexts::within() keeps the frame, so it
 * keeps the nonce. Outside every render the generator keeps one nonce of its own.
 */
final class NonceGenerator
{
    /** The nonce outside every render. */
    private ?string $nonce = null;

    /** The nonce every new one gets while a test fixed it (fixedForTests()); null: random. */
    private static ?string $fixed = null;

    public function get(): string
    {
        if (Contexts::depth() === 0) {
            return $this->nonce ??= self::fresh();
        }
        $source = $this->current()->source();

        return $source->value ??= self::fresh();
    }

    /**
     * Answer the rest of the current frame with $nonce, the nonce of the page a fragment request
     * came from (a hypermedia client sends it back: HypermediaProtocol::pageNonce()), so the
     * scripts and styles the response puts into that page pass its Content-Security-Policy. It
     * is untrusted client input: core adopts it for a fragment request only, in that request's
     * own frame, and refuses to store that response in the page cache. A value that is no
     * base64 nonce of at least 22 characters (16 random bytes) is ignored (false), and so is any
     * value outside every render: the frame's end is where the adopted nonce ends.
     *
     * @since 0.81.0
     */
    public function adoptInFrame(string $nonce): bool
    {
        if (Contexts::depth() === 0 || preg_match('#^[A-Za-z0-9+/_-]{22,64}={0,2}$#', $nonce) !== 1) {
            return false;
        }
        $current = $this->current();
        // A frame that shared another's nonce stops sharing it: the adopted one is its own.
        $current->from = null;
        $current->value = $nonce;
        $current->adopted = true;

        return true;
    }

    /** Whether the nonce in use is an adopted one (adoptInFrame()), not the render's own. */
    public function adopted(): bool
    {
        return Contexts::depth() > 0 && $this->current()->source()->adopted;
    }

    /**
     * The current frame uses the nonce of $outer, the frame around it (a request a page asks for
     * in-process: what it renders goes into that page). Call it before the frame used a nonce.
     *
     * @since 0.81.0
     *
     * @throws \LogicException when the current frame has a nonce of its own already
     */
    public function share(RenderFrame $outer): void
    {
        $current = $this->current();
        if ($current->value !== null) {
            throw new \LogicException('NonceGenerator::share() comes too late: the render frame has a nonce of its own already.');
        }
        $current->from = self::of($outer);
    }

    /**
     * Gives every nonce made from now on the value $nonce (null: random again), so a test can
     * compare two renders byte for byte (the isolation contract). Not for production code.
     *
     * @since 0.81.0
     * @internal tests only
     */
    public static function fixedForTests(?string $nonce): void
    {
        self::$fixed = $nonce;
    }

    private function current(): FrameNonce
    {
        return self::of(Contexts::frame());
    }

    private static function of(RenderFrame $frame): FrameNonce
    {
        return $frame->slot(FrameNonce::class, static fn (): FrameNonce => new FrameNonce());
    }

    private static function fresh(): string
    {
        return self::$fixed ?? base64_encode(random_bytes(16));
    }
}
