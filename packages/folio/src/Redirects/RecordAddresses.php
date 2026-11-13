<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\RedirectPath;

/**
 * The address book: the public addresses each record last had (folio_addresses, one row per
 * record and locale), compared after every change that can move or remove them, to write the
 * automatic redirect entries.
 *
 * - sync() after a publish or a live save: old addresses redirect to the record (one entry per
 *   address, all pointing at the record itself, so renaming twice never chains), the address a
 *   record holds now never keeps an entry (the page wins), a record back on the site takes its
 *   "removed" entries back, and a record that left the site is removed() without a target.
 * - removed() when a record leaves the site (unpublished, deleted, or its status changed): its
 *   addresses become "removed" entries to the chosen target as SiteTarget::final() resolves it
 *   (none when it leads back to the record or nowhere: they answer 404 until an editor picks
 *   one); with a target, entries that already pointed at the record follow it.
 * - Addresses a page takes and addresses that get an entry leave the not-found list.
 *
 * Locales are those of the URL: with prefixes (i18n url_strategy "prefix") every content locale
 * keeps its own address, stored with its locale as LocaleMiddleware hands it to the redirect
 * lookup; without prefixes ("none") only the default language's address is kept, language-
 * neutral (locale ''). Paths are in RedirectPath's one form.
 *
 * Nothing here ever breaks the change it follows: every failure (a table not migrated yet) is
 * passed to $warn and swallowed.
 */
final class RecordAddresses
{
    /** @var \Closure(): LiveRecords */
    private readonly \Closure $live;

    /**
     * @param LiveRecords|\Closure(): LiveRecords $live a closure when LiveRecords is built later
     *                                                  (it reads through the Publisher this hooks into)
     * @param bool                                $prefixed whether URLs carry the locale (url_strategy "prefix")
     * @param (\Closure(string): void)|null       $warn
     * @param NotFoundEntries|null                $notFound addresses a page takes or an entry answers leave the not-found list
     * @param SiteTarget|null                     $targets  where a removal target really leads (none: taken as given)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly RedirectEntries $entries,
        private readonly FrontendResolver $resolver,
        LiveRecords|\Closure $live,
        private readonly bool $prefixed = true,
        private readonly ?\Closure $warn = null,
        private readonly ?NotFoundEntries $notFound = null,
        private readonly ?SiteTarget $targets = null,
    ) {
        $this->live = $live instanceof LiveRecords ? static fn (): LiveRecords => $live : $live;
    }

    /** The row id of a record's address in a locale. */
    public static function id(string $type, string $id, string $locale): string
    {
        return substr(hash('sha256', implode("\n", [$type, $id, $locale])), 0, 32);
    }

    /**
     * Compares the record's current public addresses with the stored ones (or, when none are
     * stored, with $previousLive's) and writes the automatic entries.
     *
     * @return bool true when the record has just left the site (it had addresses and has none now)
     */
    public function sync(string $type, string $id, ?DynamicRecord $previousLive = null): bool
    {
        try {
            if ($this->resolver->routeFor($type) === null) {
                return false;
            }
            $current = $this->addressesOf(($this->live)()->find($type, $id));
            $rows = $this->stored($type, $id);
            $stored = $rows === [] && $previousLive !== null ? $this->addressesOf($previousLive) : $rows;

            if ($current === []) {
                if ($stored === []) {
                    return false;
                }
                $this->remove($type, $id, null, $stored);

                return true;
            }

            foreach ($stored as $locale => $path) {
                if (($current[$locale] ?? null) === $path) {
                    continue;
                }
                $this->entries->storeUnchecked([
                    'path' => $path,
                    'locale' => $locale,
                    'area' => false,
                    'mode' => AreaMode::One->value,
                    'target_type' => $type,
                    'target_id' => $id,
                    'status' => 301,
                    'kind' => 'auto',
                    'source_type' => $type,
                    'source_id' => $id,
                ]);
                $this->notFound?->forget($locale, $path);
                if (!isset($current[$locale])) {
                    $this->deleteRow($type, $id, $locale);
                }
            }
            // Back on the site: its addresses an earlier removal sent elsewhere, or nowhere, point here again.
            foreach ($this->entries->sourcedBy($type, $id) as $entry) {
                if ($entry['kind'] === 'removed' || ($entry['target_type'] === null && $entry['target_url'] === null)) {
                    $this->entries->storeUnchecked(['target_type' => $type, 'target_id' => $id, 'kind' => 'auto'] + $entry);
                }
            }
            // The page wins at every address it holds now; written last, so nothing above lands there.
            foreach ($current as $locale => $path) {
                $entry = $this->entries->at($locale, $path);
                if ($entry !== null) {
                    $this->entries->delete($entry['id']);
                }
                $this->notFound?->forget($locale, $path);
                if (($rows[$locale] ?? null) !== $path) {
                    $this->writeRow($type, $id, $locale, $path);
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->warn("Addresses of {$type} {$id} not synced: " . $e->getMessage());

            return false;
        }
    }

    /**
     * The record left the site (unpublished or deleted): each stored address becomes a "removed"
     * entry to $target (none: it answers 404), and so do this record's own automatic entries.
     * The target is taken where it really leads (finalTarget()); one that leads back to the
     * record, to a record off the site, or nowhere is none.
     * With a target, the entries other sources made for the record follow it too, keeping what
     * they are (an area stays an area, a hand-made entry stays hand-made), and entries an earlier
     * removal of this record left without a target get it. Without one, those other entries keep
     * pointing at the record: 404 while it is off, working again when it comes back.
     */
    public function removed(string $type, string $id, ?RemovalTarget $target): void
    {
        try {
            if ($this->resolver->routeFor($type) === null) {
                return;
            }
            $this->remove($type, $id, $target, $this->stored($type, $id));
        } catch (\Throwable $e) {
            $this->warn("Old addresses of {$type} {$id} not redirected: " . $e->getMessage());
        }
    }

    /**
     * Where a removal target really sends visitors (SiteTarget::final()): a URL of a page becomes
     * its record, a URL that redirects already its final target, a record that is not live (a
     * draft, an unknown or a deleted one) no target. Taken as given when the storage fails.
     */
    public function finalTarget(?RemovalTarget $target): ?RemovalTarget
    {
        if ($target === null || $this->targets === null) {
            return $target;
        }
        try {
            return $this->targets->final($target);
        } catch (\Throwable $e) {
            $this->warn('Redirect target not resolved: ' . $e->getMessage());

            return $target;
        }
    }

    /**
     * How many addresses each of these records holds (of() for a whole list page, in one read): the
     * count unpublishing or deleting it takes off the site. Records without any are left out.
     *
     * @since 0.75
     *
     * @param list<string> $ids
     * @return array<string, int> record id => addresses
     */
    public function counts(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $wanted = array_flip(array_map('strval', $ids));
            $counts = [];
            $query = $this->dm->queryIn(AddressTable::definition())->where('type', $type)
                ->whereAny([['field' => 'record_id', 'operator' => 'IN', 'value' => array_values(array_map('strval', $ids))]]);
            foreach ($query->get()->items() as $row) {
                $id = (string) $row->get('record_id');
                // An IN on a text column compares case-insensitively on MySQL: exact ids only.
                if ((string) $row->get('type') === $type && isset($wanted[$id])) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }

            return $counts;
        } catch (\Throwable $e) {
            $this->warn("Addresses of {$type} not read: " . $e->getMessage());

            return [];
        }
    }

    /** @return array<string, string> locale => path, as stored */
    public function of(string $type, string $id): array
    {
        try {
            $stored = $this->stored($type, $id);
            // In the type's language order (the default first), whatever order the storage keeps.
            $registry = $this->dm->getTypeRegistry();
            if ($registry !== null && $registry->has($type)) {
                $order = array_flip(['', ...$registry->get($type)->locales->available]);
                uksort($stored, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));
            }

            return $stored;
        } catch (\Throwable $e) {
            $this->warn("Addresses of {$type} {$id} not read: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Records the current addresses of every record on the site that has none stored yet (a site
     * upgraded to redirects): no entries are written. For `preflow migrate`.
     *
     * @return list<string>
     */
    public function seed(): array
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null) {
            return [];
        }
        $count = 0;
        $failed = [];
        foreach ($registry->keys() as $type) {
            // One type or record that fails is reported; the others are still recorded.
            try {
                if ($this->resolver->routeFor($type) === null) {
                    continue;
                }
                $known = [];
                foreach ($this->dm->queryIn(AddressTable::definition())->where('type', $type)->get()->items() as $row) {
                    if ((string) $row->get('type') === $type) {
                        $known[(string) $row->get('record_id')] = true;
                    }
                }
                $records = ($this->live)()->of($type);
            } catch (\Throwable $e) {
                $failed[] = "{$type}: " . $e->getMessage();
                continue;
            }
            foreach ($records as $record) {
                $id = (string) $record->getId();
                if (isset($known[$id])) {
                    continue;
                }
                try {
                    $addresses = $this->addressesOf($record);
                    foreach ($addresses as $locale => $path) {
                        $this->writeRow($type, $id, $locale, $path);
                        $this->notFound?->forget($locale, $path);
                    }
                    $count += $addresses === [] ? 0 : 1;
                } catch (\Throwable $e) {
                    $failed[] = "{$type} {$id}: " . $e->getMessage();
                }
            }
        }

        $lines = [sprintf('folio: recorded the public addresses of %d %s.', $count, $count === 1 ? 'record' : 'records')];
        foreach ($failed as $failure) {
            $this->warn('Addresses not recorded: ' . $failure);
            $lines[] = 'folio: addresses not recorded for ' . $failure;
        }

        return $lines;
    }

    /**
     * @param array<string, string> $stored locale => path
     */
    private function remove(string $type, string $id, ?RemovalTarget $target, array $stored): void
    {
        $target = $this->finalFor($type, $id, $target, $stored);
        $to = [
            'target_type' => $target?->type,
            'target_id' => $target?->id,
            'target_url' => $target?->url,
        ];

        foreach ($this->entries->targeting($type, $id) as $entry) {
            $own = $entry['source_type'] === $type && $entry['source_id'] === $id;
            if ($target === null && !$own) {
                // Another source's entry (hand-made, an area, another record's) keeps pointing here:
                // a 404 while the record is off, working again when it comes back.
                continue;
            }
            $this->entries->storeUnchecked(['kind' => $entry['kind'] === 'auto' ? 'removed' : $entry['kind']] + $to + $entry);
        }
        if ($target !== null) {
            foreach ($this->entries->sourcedBy($type, $id) as $entry) {
                if ($entry['target_type'] === null && $entry['target_url'] === null) {
                    $this->entries->storeUnchecked($to + $entry);
                }
            }
        }
        foreach ($stored as $locale => $path) {
            $this->entries->storeUnchecked([
                'path' => $path,
                'locale' => $locale,
                'area' => false,
                'mode' => AreaMode::One->value,
                'status' => 301,
                'kind' => 'removed',
                'source_type' => $type,
                'source_id' => $id,
            ] + $to);
            $this->notFound?->forget($locale, $path);
            $this->deleteRow($type, $id, $locale);
        }
    }

    /**
     * The target the record's old links get, or none: never the record itself (by its id, at one
     * of its own addresses, or through an entry leading back to it), nor anything that leads
     * nowhere.
     *
     * @param array<string, string> $stored locale => path
     */
    private function finalFor(string $type, string $id, ?RemovalTarget $target, array $stored): ?RemovalTarget
    {
        if ($target === null) {
            return null;
        }
        if ($target->url !== null && $this->targets !== null) {
            $at = $this->targets->address($target->url);
            if ($at !== null && ($stored[$at[0]] ?? null) === $at[1]) {
                return null;
            }
        }
        $target = $this->targets !== null ? $this->targets->final($target) : $target;

        return $target === null || $target->isRecord($type, $id) ? null : $target;
    }

    /** @return array<string, string> locale => path of a live record ([] for none) */
    private function addressesOf(?DynamicRecord $record): array
    {
        if ($record === null) {
            return [];
        }
        $locales = $record->getType()->locales;
        $addresses = [];
        foreach ($this->prefixed ? $locales->available : [$locales->default] as $locale) {
            $path = $this->resolver->pathFor($record, $locale);
            $path = $path === null ? null : RedirectPath::normalize($path);
            if ($path !== null) {
                $addresses[$this->prefixed ? $locale : ''] = $path;
            }
        }

        return $addresses;
    }

    /** @return array<string, string> locale => path; exact matches only (MySQL compares value columns case-insensitively) */
    private function stored(string $type, string $id): array
    {
        $addresses = [];
        $query = $this->dm->queryIn(AddressTable::definition())->where('type', $type)->where('record_id', $id);
        foreach ($query->get()->items() as $row) {
            if ((string) $row->get('type') === $type && (string) $row->get('record_id') === $id) {
                $addresses[(string) $row->get('locale')] = (string) $row->get('path');
            }
        }

        return $addresses;
    }

    private function writeRow(string $type, string $id, string $locale, string $path): void
    {
        $this->dm->saveType(DynamicRecord::fromArray(AddressTable::definition(), [
            'id' => self::id($type, $id, $locale),
            'type' => $type,
            'record_id' => $id,
            'locale' => $locale,
            'path' => $path,
        ]), false);
    }

    private function deleteRow(string $type, string $id, string $locale): void
    {
        $rowId = self::id($type, $id, $locale);
        if ($this->dm->findIn(AddressTable::definition(), $rowId) !== null) {
            $this->dm->deleteIn(AddressTable::definition(), $rowId);
        }
    }

    private function warn(string $message): void
    {
        if ($this->warn !== null) {
            ($this->warn)($message);
        }
    }
}
