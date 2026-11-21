<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Preview\PreviewGrant;

/**
 * The session the container hands out: it follows the current render. Inside a render that
 * views the site as a role (a synthetic viewer) or was admitted by a preview token (a
 * PreviewGrant), it is an empty in-memory session of that render frame (IsolatedSession), so
 * the editor's session is never read, written, regenerated or closed by the preview; everywhere
 * else (a visitor's request, the admin, the console) it is the real one. SessionMiddleware keeps
 * the real session itself.
 *
 * Code that reads PHP's $_SESSION directly is not covered: only the SessionInterface is fenced.
 *
 * @since 0.81.0
 */
final class RenderSession implements SessionInterface
{
    public function __construct(private readonly SessionInterface $real) {}

    /** The session of the current render: an isolated one inside a role view or a preview, else the real one. */
    public function current(): SessionInterface
    {
        if (Contexts::depth() === 0) {
            return $this->real;
        }
        $ctx = Contexts::current();
        if (!$ctx->viewer->isSynthetic() && $ctx->get(PreviewGrant::class) === null) {
            return $this->real;
        }

        return Contexts::frame()->slot(IsolatedSession::class, static fn (): IsolatedSession => new IsolatedSession());
    }

    public function start(): void
    {
        $this->current()->start();
    }

    public function getId(): string
    {
        return $this->current()->getId();
    }

    public function regenerate(): void
    {
        $this->current()->regenerate();
    }

    public function invalidate(): void
    {
        $this->current()->invalidate();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->current()->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->current()->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->current()->has($key);
    }

    public function remove(string $key): void
    {
        $this->current()->remove($key);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->current()->flash($key, $value);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->current()->getFlash($key, $default);
    }

    public function isStarted(): bool
    {
        return $this->current()->isStarted();
    }

    public function ageFlash(): void
    {
        $this->current()->ageFlash();
    }

    public function close(): void
    {
        $this->current()->close();
    }
}
