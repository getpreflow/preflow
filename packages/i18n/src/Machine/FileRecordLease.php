<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * One file per claimed record, named by a hash of collection and id (ids may hold any characters).
 * The file holds the claim's expiry, so a worker that died without releasing blocks the record only
 * until then, and a token that is new with every claim: the lease remembers the tokens of its own
 * claims, so a worker that overran its claim cannot release or renew the claim of the worker that
 * took the record over after it.
 */
final class FileRecordLease implements RecordLease
{
    private readonly \Closure $now;

    /** @var array<string, string> token of each claim this lease holds, by lease file path */
    private array $tokens = [];

    public function __construct(private readonly string $dir, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    public function claim(string $collection, string $id, int $ttlSeconds = 600): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create lease directory {$this->dir}.");
        }
        $path = $this->path($collection, $id);
        $now = ($this->now)();
        $token = bin2hex(random_bytes(8));
        $content = ($now + $ttlSeconds) . ' ' . $token;

        if ($this->create($path, $content)) {
            $this->tokens[$path] = $token;

            return true;
        }

        // Takeovers are serialised by a lock file, and the claim is re-read under it, so of two
        // workers that both saw the expired claim only the first one takes it.
        return $this->locked($path, function () use ($path, $content, $token, $now, $ttlSeconds): bool {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                // Released meanwhile: create it as a first claim would, as another claimer may do right now.
                $taken = $this->create($path, $content);
            } elseif ($this->heldAt($path, $now, $ttlSeconds)) {
                return false;
            } else {
                $taken = $this->write($path, $content);
            }
            if ($taken) {
                $this->tokens[$path] = $token;
            }

            return $taken;
        });
    }

    public function renew(string $collection, string $id, int $ttlSeconds = 600): bool
    {
        $path = $this->path($collection, $id);
        $token = $this->tokens[$path] ?? null;
        if ($token === null) {
            return false;
        }

        return $this->locked($path, function () use ($path, $token, $ttlSeconds): bool {
            if ($this->read($path)['token'] !== $token) {
                unset($this->tokens[$path]); // taken over by someone else

                return false;
            }

            return $this->write($path, (($this->now)() + $ttlSeconds) . ' ' . $token);
        });
    }

    public function release(string $collection, string $id): void
    {
        $path = $this->path($collection, $id);
        $token = $this->tokens[$path] ?? null;
        unset($this->tokens[$path]);
        if ($token === null) {
            return;
        }

        $this->locked($path, function () use ($path, $token): bool {
            if ($this->read($path)['token'] === $token) {
                @unlink($path);
            }

            return true;
        });
    }

    /** Creates the lease file with $content; fails when it exists ('x') or the write does not complete. */
    private function create(string $path, string $content): bool
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content)) {
            // A half-written claim would hold the record without an expiry; better not to hold it at all.
            @unlink($path);

            return false;
        }

        return true;
    }

    private function write(string $path, string $content): bool
    {
        if (@file_put_contents($path, $content) === strlen($content)) {
            return true;
        }
        @unlink($path);

        return false;
    }

    /**
     * Whether the claim in $path still holds at $now. A file that is empty or cannot be read either
     * belongs to a holder between creating it and writing its expiry, or to one that died right
     * there; it counts as held until it is older than $ttlSeconds.
     */
    private function heldAt(string $path, int $now, int $ttlSeconds): bool
    {
        $claim = $this->read($path);
        if ($claim['expiry'] !== null) {
            return $claim['expiry'] > $now;
        }
        $modified = @filemtime($path);

        return $modified !== false && $now - $modified < $ttlSeconds;
    }

    /**
     * Lease files hold "{expiry} {token}"; a file with only an expiry has no token anyone can match.
     *
     * @return array{expiry: ?int, token: ?string}
     */
    private function read(string $path): array
    {
        clearstatcache(true, $path);
        $stored = @file_get_contents($path);
        if ($stored === false || preg_match('/\A(\d+)(?: ([0-9a-f]+))?\z/', trim($stored), $m) !== 1) {
            return ['expiry' => null, 'token' => null];
        }

        return ['expiry' => (int) $m[1], 'token' => $m[2] ?? null];
    }

    /** @param \Closure(): bool $work */
    private function locked(string $path, \Closure $work): bool
    {
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            return false;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return false;
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(string $collection, string $id): string
    {
        return rtrim($this->dir, '/') . '/' . sha1($collection . "\0" . $id);
    }
}
