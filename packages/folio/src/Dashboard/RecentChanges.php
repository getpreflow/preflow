<?php

declare(strict_types=1);

namespace Preflow\Folio\Dashboard;

use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Data\DataManager;
use Preflow\Data\TypeFamily;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Content\TypeListing;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminFormat;
use Preflow\Folio\Http\AdminNav;
use Preflow\Folio\Users\AuthorNames;
use Preflow\Publishing\Publisher;

/**
 * The activity log as rows for Folio: the dashboard's "Recent changes" and the All activity
 * screen. Only what the signed-in person may open is ever read: the log is asked for the subjects
 * they may see (a type: {type}.view; "asset": assets.view; "redirect": redirects.manage), so no
 * title of anything else reaches a page (Review Focus 1). No permission of its own.
 *
 * A row: the type tile (the type's family and glyph), the stored title, a link to the record
 * (none once the record is gone: its title as stored), "what" (the subject's name, up to two
 * changed fields' labels, what happened: "Post · Title, Body edited · draft saved"), who
 * (AuthorNames) and when (AdminFormat::relative()).
 *
 * @since 0.77
 */
final class RecentChanges
{
    public const string ASSET = 'asset';

    public const string REDIRECT = 'redirect';

    /** The asset library's title of a private file: a translation key (MediaLibrary::PRIVATE_TITLE). */
    private const string PRIVATE_TITLE = 'media.activity.private_file';

    /** How many changed fields "what" names; the rest is counted ("and 2 more"). */
    private const int FIELDS = 2;

    /**
     * @param (\Closure(list<string>): list<string>)|null $assets    of these asset ids, the ones still in the library; null: no library (no asset rows)
     * @param (\Closure(list<string>): list<string>)|null $redirects of these redirect ids, the ones that still exist; null: no redirects (no rows)
     */
    public function __construct(
        private readonly ActivityLog $log,
        private readonly TypeCatalog $catalog,
        private readonly DisplayLabels $labels,
        private readonly DataManager $dm,
        private readonly string $prefix,
        private readonly ?AuthorNames $authors = null,
        private readonly ?Publisher $publisher = null,
        private readonly ?\Closure $assets = null,
        private readonly ?\Closure $redirects = null,
        private readonly AdminFormat $format = new AdminFormat(),
    ) {}

    /**
     * The subjects the person may see, in sidebar order (types by section and "order"), then the
     * assets and redirects: the type filter's options.
     *
     * @param \Closure(string): bool $can
     * @return list<array{key: string, label: string}>
     */
    public function subjects(\Closure $can): array
    {
        $sections = AdminNav::sectionOrder(array_map(static fn (TypeListing $l): string => $l->section, $this->catalog->all()));
        $listings = array_values(array_filter($this->catalog->all(), static fn (TypeListing $l): bool => $can($l->key . '.view')));
        usort($listings, static fn (TypeListing $a, TypeListing $b): int => [array_search($a->section, $sections, true), $a->order] <=> [array_search($b->section, $sections, true), $b->order]);
        $subjects = array_map(fn (TypeListing $l): array => ['key' => $l->key, 'label' => $this->labels->type($l->key)], $listings);
        if ($this->assets !== null && $can('assets.view')) {
            $subjects[] = ['key' => self::ASSET, 'label' => FolioText::get('folio.nav.assets')];
        }
        if ($this->redirects !== null && $can('redirects.manage')) {
            $subjects[] = ['key' => self::REDIRECT, 'label' => FolioText::get('folio.nav.redirects')];
        }

        return $subjects;
    }

    /**
     * A page of rows, newest first, and the cursor of the next (null on the last). $subject and
     * $person narrow it; a subject the person may not see is no filter (null).
     *
     * @param \Closure(string): bool $can
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function page(\Closure $can, int $limit, ?string $subject = null, ?string $person = null, ?string $before = null): array
    {
        $allowed = array_column($this->subjects($can), 'key');
        if ($allowed === []) {
            return ['rows' => [], 'next' => null];
        }
        $subjects = $subject !== null && in_array($subject, $allowed, true) ? [$subject] : $allowed;
        $page = $this->log->recent($limit, $subjects, $before, $person !== null && $person !== '' ? $person : null);
        $items = array_values(array_filter($page->items, static fn (Activity $a): bool => in_array($a->subject, $allowed, true)));

        return ['rows' => $this->rows($items), 'next' => $page->next];
    }

    /**
     * Who did something the person may see (of $subject when it is one of theirs), the latest
     * first: the person filter's options.
     *
     * @param \Closure(string): bool $can
     * @return list<array{id: string, label: string}>
     */
    public function people(\Closure $can, ?string $subject = null): array
    {
        $allowed = array_column($this->subjects($can), 'key');
        if ($allowed === []) {
            return [];
        }
        $subjects = $subject !== null && in_array($subject, $allowed, true) ? [$subject] : $allowed;

        return array_map(fn (string $by): array => ['id' => $by, 'label' => $this->authors?->label($by) ?? $by], $this->log->people($subjects));
    }

    /**
     * @param list<Activity> $items
     * @return list<array<string, mixed>>
     */
    private function rows(array $items): array
    {
        $live = $this->live($items);
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->row($item, isset($live[$item->subject][$item->id]));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(Activity $item, bool $live): array
    {
        $listing = $this->catalog->get($item->subject);
        [$family, $icon, $name, $url, $drawer] = match (true) {
            $item->subject === self::ASSET => [TypeFamily::Asset, 'ui-library', FolioText::get('folio.activity.asset'), $this->prefix . '/assets/' . rawurlencode($item->id), false],
            $item->subject === self::REDIRECT => [null, 'ui-redirect', FolioText::get('folio.activity.redirect'), $this->prefix . '/redirects/' . rawurlencode($item->id), true],
            default => [
                $listing?->family,
                $listing?->glyph() ?? 'ui-file',
                $this->labels->one($item->subject),
                $listing !== null && $listing->singleton
                    ? $this->prefix . '/' . rawurlencode($item->subject)
                    : $this->prefix . '/' . rawurlencode($item->subject) . '/' . rawurlencode($item->id) . '/edit',
                false,
            ],
        };
        $title = $item->subject === self::ASSET && $item->title === self::PRIVATE_TITLE ? $this->privateTitle() : $item->title;

        return [
            'subject' => $item->subject,
            'family' => $family,
            'icon' => $icon,
            'title' => $title !== '' ? $title : $item->id,
            'url' => $live ? $url : null,
            'drawer' => $drawer,
            'what' => implode(' · ', array_filter([$name, $this->fields($item), $this->kind($item)], static fn (string $part): bool => $part !== '')),
            'who' => $item->by !== null ? ($this->authors?->label($item->by) ?? $item->by) : null,
            'at' => $item->at,
            'when' => $this->format->relative($item->at),
            'when_full' => $this->format->date($item->at, 'datetime'),
        ];
    }

    /** "Title, Body edited", "Title, Body and 3 more edited"; '' when nothing says or the kind names no fields. */
    private function fields(Activity $item): string
    {
        if ($item->changed === [] || !in_array($item->kind, [ActivityKind::Saved, ActivityKind::Published, ActivityKind::Scheduled], true) || $this->catalog->get($item->subject) === null) {
            return '';
        }
        $labels = array_map(fn (string $field): string => $this->labels->field($item->subject, $field), array_slice($item->changed, 0, self::FIELDS));
        $more = count($item->changed) - count($labels);
        $fields = implode(', ', $labels);
        if ($more > 0) {
            $fields = FolioText::choice('folio.activity.fields_more', $more, ['fields' => $fields, 'count' => $this->format->number($more)]);
        }

        return FolioText::choice('folio.activity.edited', count($item->changed), ['fields' => $fields]);
    }

    /** What happened: a Saved of a publishing type is a draft. */
    private function kind(Activity $item): string
    {
        if ($item->kind === ActivityKind::Saved && ($this->publisher?->enabled($item->subject) ?? false)) {
            return FolioText::get('folio.activity.kinds.draft_saved');
        }

        return FolioText::get('folio.activity.kinds.' . $item->kind->value);
    }

    /** The library's own text for a private file (its translation group "media"), else Folio's. */
    private function privateTitle(): string
    {
        $text = FolioText::get(self::PRIVATE_TITLE);

        return $text !== self::PRIVATE_TITLE ? $text : FolioText::get('folio.activity.private_file');
    }

    /**
     * Which records, assets and redirects of the rows still exist, by subject: one query per type
     * (its id field IN the ids), the library and the redirects asked once each. A type that can't
     * be read counts as existing (its link stays).
     *
     * @param list<Activity> $items
     * @return array<string, array<string, true>>
     */
    private function live(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[$item->subject][$item->id] = $item->id;
        }
        $live = [];
        foreach ($ids as $subject => $wanted) {
            $wanted = array_values($wanted);
            $found = match ($subject) {
                self::ASSET => $this->assets !== null ? ($this->assets)($wanted) : [],
                self::REDIRECT => $this->redirects !== null ? ($this->redirects)($wanted) : [],
                default => $this->records((string) $subject, $wanted),
            };
            $live[$subject] = array_fill_keys(array_map(strval(...), $found), true);
        }

        return $live;
    }

    /** @param list<string> $ids @return list<string> */
    private function records(string $type, array $ids): array
    {
        try {
            $idField = $this->dm->getTypeRegistry()->get($type)->idField;
            $found = [];
            foreach ($this->dm->queryType($type)->where($idField, 'IN', $ids)->get()->items() as $record) {
                $found[] = (string) $record->getId();
            }

            return $found;
        } catch (\Throwable) {
            return $ids;
        }
    }
}
