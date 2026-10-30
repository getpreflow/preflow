<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** One JSON file per job under a directory. Ids become file names, so only a safe alphabet is accepted. */
final class FileJobStore implements JobStore
{
    public function __construct(private readonly string $dir) {}

    public function save(Job $job): void
    {
        if (!self::safe($job->id)) {
            throw new \InvalidArgumentException('Unsafe job id.');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create job directory {$this->dir}.");
        }
        $path = $this->path($job->id);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        // Write beside the target, then rename: a reader never sees a half-written job.
        if (file_put_contents($tmp, json_encode($job->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write job {$job->id}.");
        }
    }

    public function get(string $id): ?Job
    {
        if (!self::safe($id) || !is_file($this->path($id))) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path($id)), true);

        return is_array($data) && isset($data['id'], $data['collection']) ? Job::fromArray($data) : null;
    }

    private function path(string $id): string
    {
        return rtrim($this->dir, '/') . '/' . $id . '.json';
    }

    private static function safe(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $id) === 1;
    }
}
