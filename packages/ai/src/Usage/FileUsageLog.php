<?php

declare(strict_types=1);

namespace Preflow\Ai\Usage;

use Preflow\Ai\TokenUsage;

/**
 * One JSON line per call in {dir}/usage-YYYY-MM.jsonl. Append-only with an exclusive lock, so
 * concurrent requests and CLI runs interleave whole lines; a month per file keeps each small
 * enough to read in full. Unreadable lines are skipped: a damaged line must not hide the rest.
 */
final class FileUsageLog implements UsageLog
{
    public function __construct(private readonly string $dir) {}

    public function record(UsageEntry $e): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the AI usage directory.');
        }

        $line = json_encode([
            'at' => $e->at->format(\DateTimeInterface::ATOM),
            'purpose' => $e->purpose,
            'provider' => $e->provider,
            'model' => $e->model,
            'input' => $e->usage->input,
            'output' => $e->usage->output,
            'cached' => $e->usage->cachedInput,
            'cost' => $e->costUsd,
            'failed' => $e->failed,
        ], JSON_THROW_ON_ERROR) . "\n";

        if (file_put_contents($this->file($e->at), $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the AI usage log.');
        }
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $entries = [];
        $month = $from->modify('first day of this month')->setTime(0, 0);
        while ($month <= $to) {
            $file = $this->file($month);
            if (is_file($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $entry = $this->parse($line);
                    if ($entry !== null && $entry->at >= $from && $entry->at <= $to) {
                        $entries[] = $entry;
                    }
                }
            }
            $month = $month->modify('first day of next month');
        }
        usort($entries, static fn (UsageEntry $a, UsageEntry $b): int => $a->at <=> $b->at);

        return $entries;
    }

    private function file(\DateTimeImmutable $at): string
    {
        return rtrim($this->dir, '/') . '/usage-' . $at->format('Y-m') . '.jsonl';
    }

    private function parse(string $line): ?UsageEntry
    {
        $d = json_decode($line, true);
        if (!is_array($d) || !is_string($d['at'] ?? null)) {
            return null;
        }
        try {
            $at = new \DateTimeImmutable($d['at']);
        } catch (\Exception) {
            return null;
        }

        return new UsageEntry(
            $at,
            (string) ($d['purpose'] ?? ''),
            (string) ($d['provider'] ?? ''),
            (string) ($d['model'] ?? ''),
            new TokenUsage((int) ($d['input'] ?? 0), (int) ($d['output'] ?? 0), (int) ($d['cached'] ?? 0)),
            isset($d['cost']) ? (float) $d['cost'] : null,
            (bool) ($d['failed'] ?? false),
        );
    }
}
