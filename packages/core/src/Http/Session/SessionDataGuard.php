<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

/**
 * Hides the visitor's native session data ($_SESSION) while something renders
 * as an anonymous visitor, and puts it back afterwards. A shutdown hook puts it
 * back too when the render never returns (timeout, out of memory): PHP runs
 * shutdown functions before it writes the session, so the visitor is never
 * signed out by an emptied session.
 */
final class SessionDataGuard
{
    private int $depth = 0;

    private bool $had = false;

    private mixed $saved = null;

    private bool $hooked = false;

    /** Swap in an empty session (nested calls only count). */
    public function hide(): void
    {
        if ($this->depth++ > 0) {
            return;
        }
        if (!$this->hooked) {
            register_shutdown_function($this->onShutdown(...));
            $this->hooked = true;
        }

        $this->had = isset($_SESSION);
        $this->saved = $_SESSION ?? null;
        if ($this->had) {
            $_SESSION = ['_flash' => ['current' => [], 'previous' => []]];
        }
    }

    /** Put the visitor's data back once the outermost hide() is matched. */
    public function restore(): void
    {
        if ($this->depth === 0 || --$this->depth > 0) {
            return;
        }

        if ($this->had) {
            $_SESSION = $this->saved;
        } else {
            unset($_SESSION);
        }
        $this->saved = null;
    }

    /** Whether the visitor's data is hidden right now. */
    public function hiding(): bool
    {
        return $this->depth > 0;
    }

    /** Shutdown hook: a render that never finished still gets the data back before the session is written. */
    public function onShutdown(): void
    {
        if ($this->depth > 0) {
            $this->depth = 1;
            $this->restore();
        }
    }
}
