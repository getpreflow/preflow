<?php

declare(strict_types=1);

namespace Preflow\Media\Cleanup;

use Preflow\Media\Asset;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Usage\AssetUsage;

/**
 * Finds unused assets of every kind, public and private: live (not in the bin), uploaded
 * longer ago than cleanup.min_age_days (a fresh upload not yet attached is left alone), and
 * reported by no usage source, blocking or not (records, earlier revisions, a live copy of
 * an original, a poster). It only lists; moving them into the bin is MediaLibrary::delete().
 *
 * Cleanup only knows the usages reported through usage sources: an app that stores asset
 * ids or URLs anywhere else and registers no AssetReferenceSource loses those files to it.
 * Hence it is opt-in (cleanup.enabled), and binned assets can be restored.
 */
final class AssetCleanup
{
    /** The kinds a filter takes (Asset::kind()): every file that is not an image or a video is a document. */
    public const array KINDS = ['image', 'video', 'document'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param array{enabled: bool, min_age_days: int, bin_days: int} $settings config/media.php cleanup (MediaConfig::$cleanup)
     * @param (\Closure(): \DateTimeImmutable)|null $now
     */
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly AssetUsage $usage,
        private readonly array $settings = MediaConfig::CLEANUP_DEFAULTS,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** Whether the site turned cleanup on (config/media.php cleanup.enabled; off by default). */
    public function isEnabled(): bool
    {
        return $this->settings['enabled'];
    }

    /**
     * The unused assets among those the filters select, oldest upload first. In chunks:
     * $offset skips that many selected assets and $limit checks at most that many (asking
     * the usage sources is the slow part), so a chunk can hold fewer candidates than $limit;
     * selected() says how many there are to check.
     *
     * Filters: "kind" (image, video or document), "by" (the uploader, created_by),
     * "private" (true: private assets only, false: public ones only), "older_than_days"
     * (uploaded longer ago than that; default cleanup.min_age_days).
     *
     * @param array{kind?: string, by?: string, private?: bool, older_than_days?: int} $filters
     * @return list<CleanupCandidate>
     * @throws \InvalidArgumentException for an unknown kind or a negative age
     */
    public function candidates(array $filters = [], int $offset = 0, ?int $limit = null): array
    {
        $reason = 'no source reports it (asked: ' . (implode(', ', $this->usage->sourceNames()) ?: 'none') . ')';
        $candidates = [];
        foreach (array_slice($this->select($filters), max(0, $offset), $limit !== null ? max(0, $limit) : null) as $asset) {
            if ($this->usage->of($asset->id) === []) {
                $candidates[] = new CleanupCandidate($asset, $reason);
            }
        }

        return $candidates;
    }

    /**
     * How many assets the filters select: what candidates() checks, before asking the usage sources.
     *
     * @param array{kind?: string, by?: string, private?: bool, older_than_days?: int} $filters
     * @throws \InvalidArgumentException for an unknown kind or a negative age
     */
    public function selected(array $filters = []): int
    {
        return count($this->select($filters));
    }

    /**
     * @param array{kind?: string, by?: string, private?: bool, older_than_days?: int} $filters
     * @return list<Asset> live assets that pass the filters and are old enough, oldest upload first
     */
    private function select(array $filters): array
    {
        $kind = $filters['kind'] ?? null;
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown kind \"{$kind}\": use " . implode(', ', self::KINDS) . '.');
        }
        $days = $filters['older_than_days'] ?? $this->settings['min_age_days'];
        if ($days < 0) {
            throw new \InvalidArgumentException('The age in days cannot be negative.');
        }
        $by = $filters['by'] ?? null;
        $private = $filters['private'] ?? null;
        $cutoff = ($this->now)()->modify("-{$days} days");

        $selected = [];
        foreach ($this->library->all() as $asset) {
            $created = \DateTimeImmutable::createFromFormat(DATE_ATOM, $asset->createdAt) ?: null;
            if ($created === null || $created >= $cutoff) {
                continue; // recent (an unreadable date is never cleaned up)
            }
            if (($kind !== null && $asset->kind() !== $kind) || ($by !== null && $asset->createdBy !== $by) || ($private !== null && $asset->private !== $private)) {
                continue;
            }
            $selected[] = [$created, $asset->id, $asset];
        }
        // Oldest first, then by id: the same order on every run, so chunks follow on.
        usort($selected, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($selected, 2);
    }
}
