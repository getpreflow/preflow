<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

use Preflow\Core\Context\SkippedWrite;

/**
 * The SEO audit's report: when the run started and finished, the site it ran
 * on, how many pages it has done of how many, the findings, and what a later
 * run needs to continue (the queue of pages, how far it got, the run checks'
 * state). Kept as one JSON file in the app's storage
 * (storage/data/seo-audit.json), written atomically, so the admin always reads
 * a whole report. Complete once it has a finish time.
 *
 * @since 0.54
 */
final class AuditReport
{
    public const VERSION = 1;

    /** The report's place, relative to the app's base path. */
    public const PATH = 'storage/data/seo-audit.json';

    public ?string $finishedAt = null;

    /** Pages the run covers. */
    public int $total = 0;

    /** @var list<Finding> */
    public array $findings = [];

    /**
     * The data writes each audited page tried while it rendered (a view counter on GET), skipped
     * so the audit changes nothing: page URL => its writes. Pages without any are left out.
     *
     * @since 0.81.0
     * @var array<string, list<SkippedWrite>>
     */
    public array $skippedWrites = [];

    /** @var list<array{0: string, 1: string}> the pages to audit: [path, locale] */
    public array $queue = [];

    /** How many of the queue's pages are done; the next one to audit. */
    public int $cursor = 0;

    /** @var array<string, array<array-key, mixed>> run check id => its state() */
    public array $run = [];

    /**
     * @param string $startedAt when the run started (DATE_ATOM)
     * @param string $origin    the site's scheme and host
     */
    public function __construct(
        public string $startedAt,
        public string $origin,
    ) {}

    /** The report file of the app at $basePath. */
    public static function file(string $basePath): string
    {
        return rtrim($basePath, '/') . '/' . self::PATH;
    }

    public function complete(): bool
    {
        return $this->finishedAt !== null;
    }

    public function done(): int
    {
        return $this->cursor;
    }

    /** @return array{critical: int, warning: int, notice: int} findings per severity */
    public function counts(): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'notice' => 0];
        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /** The report in its JSON shape. */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'status' => $this->complete() ? 'complete' : 'incomplete',
            'origin' => $this->origin,
            'pages' => ['total' => $this->total, 'done' => $this->done()],
            'counts' => $this->counts(),
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
            'skipped_writes' => array_map(
                static fn (array $writes): array => array_map(
                    static fn (SkippedWrite $w): array => ['storage' => $w->storage, 'type' => $w->type, 'id' => $w->id, 'operation' => $w->operation],
                    $writes,
                ),
                $this->skippedWrites,
            ),
            'state' => [
                'queue' => $this->queue,
                'cursor' => $this->cursor,
                'run' => $this->run,
            ],
        ];
    }

    /** The report in $file; null when there is none, or it can't be read or is of another version. */
    public static function load(string $file): ?self
    {
        $json = is_file($file) ? @file_get_contents($file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            return null;
        }

        // A hand-edited file may hold any JSON: a wrong type is no report, not a warning.
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        $string = static fn (mixed $v): string => is_string($v) ? $v : throw new \UnexpectedValueException('Not a string.');
        $int = static fn (mixed $v): int => is_int($v) ? $v : throw new \UnexpectedValueException('Not a number.');
        $array = static fn (mixed $v): array => is_array($v) ? $v : throw new \UnexpectedValueException('Not a list or object.');
        try {
            $report = new self($string($data['started_at'] ?? ''), $string($data['origin'] ?? ''));
            $report->finishedAt = isset($data['finished_at']) ? $string($data['finished_at']) : null;
            $report->total = $int($array($data['pages'] ?? [])['total'] ?? 0);
            $report->findings = array_map(
                static fn (mixed $f): Finding => Finding::fromArray($array($f)),
                array_values($array($data['findings'] ?? [])),
            );
            $report->skippedWrites = array_map(
                static fn (mixed $writes): array => array_map(
                    static fn (mixed $w): SkippedWrite => new SkippedWrite(
                        $string($array($w)['storage'] ?? null),
                        $string($array($w)['type'] ?? null),
                        isset($array($w)['id']) ? $string($array($w)['id']) : null,
                        $string($array($w)['operation'] ?? null),
                    ),
                    array_values($array($writes)),
                ),
                array_filter($array($data['skipped_writes'] ?? []), static fn (int|string $url): bool => is_string($url), ARRAY_FILTER_USE_KEY),
            );
            $state = $array($data['state'] ?? []);
            $report->queue = array_map(
                static fn (mixed $entry): array => [$string($array($entry)[0] ?? ''), $string($array($entry)[1] ?? '')],
                array_values($array($state['queue'] ?? [])),
            );
            $report->cursor = $int($state['cursor'] ?? 0);
            $report->run = array_map($array, $array($state['run'] ?? []));
        } catch (\Throwable) {
            return null; // a value of the wrong kind, or an unknown severity
        } finally {
            restore_error_handler();
        }

        return $report;
    }

    /** Write the report to $file: to a temp file next to it, then renamed over it. */
    public function save(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Can't create the directory for the SEO audit report: {$dir}");
        }

        $data = $this->toArray();
        // An empty run state is an object in JSON, like a filled one.
        $data['state']['run'] = (object) $data['state']['run'];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $temp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $json . "\n") === false || !@rename($temp, $file)) {
            @unlink($temp);
            throw new \RuntimeException("Can't write the SEO audit report: {$file}");
        }
    }
}
