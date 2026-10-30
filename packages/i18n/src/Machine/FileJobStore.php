<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * One JSON file per job under a directory. Ids become file names, so only a safe alphabet is accepted.
 * Item messages can quote a provider's answer, so invalid UTF-8 is replaced rather than failing the save.
 */
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
        if (file_put_contents($tmp, json_encode($job->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write job {$job->id}.");
        }
    }

    public function update(string $id, \Closure $change): ?Job
    {
        if (!self::safe($id) || !is_dir($this->dir)) {
            return null;
        }
        $lock = fopen($this->path($id) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Cannot lock job {$id}.");
        }
        try {
            $job = $this->get($id);
            if ($job !== null) {
                $change($job);
                $this->save($job);
            }

            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
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

    public function recent(int $limit): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.json') ?: [];
        $times = [];
        foreach ($files as $file) {
            $times[$file] = (int) @filemtime($file);
        }
        // The last save is the file's time (save() renames a new file into place).
        uksort($times, static fn (string $a, string $b): int => [$times[$b], $b] <=> [$times[$a], $a]);

        $jobs = [];
        foreach (array_keys($times) as $file) {
            if (count($jobs) >= $limit) {
                break;
            }
            $job = $this->get(basename($file, '.json'));
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
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
