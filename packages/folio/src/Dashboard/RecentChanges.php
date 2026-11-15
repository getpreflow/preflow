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
use Preflow\Folio\Extension\Screen\ScreenRegistry;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminFormat;
use Preflow\Folio\Http\AdminNav;
use Preflow\Folio\Users\AuthorNames;
use Preflow\Publishing\Publisher;
use Preflow\I18n\Labels;
use Psr\Log\LoggerInterface;

/**
 * The activity log as rows for Folio: the dashboard's "Recent changes" and the All activity
 * screen. Only what the signed-in person may open is ever read: the log is asked for the subjects
 * they may see, so no title of anything else reaches a page (Review Focus 1). No permission of
 * its own. The subjects:
 *
 * - a type of the catalog: {type}.view;
 * - "asset": assets.view (with a library); "redirect": redirects.manage (with redirects);
 * - an app's own subject ("invoice"): when the permission "{subject}.view" is declared and held;
 *   its name is folio.activity.subjects.{subject} when the site gives that phrase, else readable.
 *
 * The reserved names (RESERVED: "asset", "redirect", "activity", and Folio's own paths, which no
 * type may take as its key) are never a type's or an app's subject: a declared "redirect.view"
 * grants nothing.
 *
 * A row: the type tile, the title (Activity::$titleKey translated when it has a text, else the
 * stored title; a redirect "old → target" while it exists), a link to the record (none once it is
 * gone, nor for an app's subject), "what" (the subject's name, up to two changed fields' labels,
 * what happened: "Post · Title, Body edited · draft saved"), who (AuthorNames, which names
 * ActivityActors' markers; none for the system) and when (AdminFormat::relative()).
 *
 * A log that fails to read is logged as a warning; the page says it could not load ("failed").
 *
 * @since 0.77
 */
final class RecentChanges
{
    public const string ASSET = 'asset';

    public const string REDIRECT = 'redirect';

    /** Subject names no type or app subject may take (ScreenRegistry::RESERVED holds them too). */
    public const array RESERVED = [self::ASSET, self::REDIRECT, 'activity'];

    /** How many changed fields "what" names; the rest is counted ("and 2 more"). */
    private const int FIELDS = 2;

    /**
     * @param (\Closure(list<string>): list<string>)|null $assets of these asset ids, the ones still in the library; null: no library (no asset rows)
     * @param (\Closure(list<string>): array<string, ?string>)|null $redirects of these redirect ids, the ones that still exist, each with its
     *        current target as text (a URL, a page's label; null: none); null: no redirects (no rows)
     * @param (\Closure(): list<string>)|null $declared the declared permissions' names (PermissionRegistry), for an app's own subjects
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
        private readonly ?\Closure $declared = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Whether a name is reserved: never a type's or an app's subject. */
    public static function reserved(string $subject): bool
    {
        return in_array($subject, self::RESERVED, true) || in_array($subject, ScreenRegistry::RESERVED, true);
    }

    /**
     * The subjects the person may see, in sidebar order (types by section and "order"), then the
     * assets and redirects, then an app's own subjects by name: the type filter's options.
     *
     * @param \Closure(string): bool $can
     * @return list<array{key: string, label: string}>
     */
    public function subjects(\Closure $can): array
    {
        $sections = AdminNav::sectionOrder(array_map(static fn (TypeListing $l): string => $l->section, $this->catalog->all()));
        $listings = array_values(array_filter($this->catalog->all(), static fn (TypeListing $l): bool => !self::reserved($l->key) && $can($l->key . '.view')));
        usort($listings, static fn (TypeListing $a, TypeListing $b): int => [array_search($a->section, $sections, true), $a->order] <=> [array_search($b->section, $sections, true), $b->order]);
        $subjects = array_map(fn (TypeListing $l): array => ['key' => $l->key, 'label' => $this->labels->type($l->key)], $listings);
        if ($this->assets !== null && $can('assets.view')) {
            $subjects[] = ['key' => self::ASSET, 'label' => FolioText::get('folio.nav.assets')];
        }
        if ($this->redirects !== null && $can('redirects.manage')) {
            $subjects[] = ['key' => self::REDIRECT, 'label' => FolioText::get('folio.nav.redirects')];
        }
        foreach ($this->appSubjects() as $subject) {
            if ($can($subject . '.view')) {
                $subjects[] = ['key' => $subject, 'label' => self::subjectLabel($subject)];
            }
        }

        return $subjects;
    }

    /**
     * A page of rows, newest first, the cursor of the next (null on the last) and whether the log
     * failed. $subject and $person narrow it; a subject the person may not see is no filter.
     *
     * @param \Closure(string): bool $can
     * @return array{rows: list<array<string, mixed>>, next: ?string, failed: bool}
     */
    public function page(\Closure $can, int $limit, ?string $subject = null, ?string $person = null, ?string $before = null): array
    {
        $allowed = array_column($this->subjects($can), 'key');
        if ($allowed === []) {
            return ['rows' => [], 'next' => null, 'failed' => false];
        }
        $subjects = $subject !== null && in_array($subject, $allowed, true) ? [$subject] : $allowed;
        try {
            $page = $this->log->recent($limit, $subjects, $before, $person !== null && $person !== '' ? $person : null);
            $items = array_values(array_filter($page->items, static fn (Activity $a): bool => in_array($a->subject, $allowed, true)));

            return ['rows' => $this->rows($items), 'next' => $page->next, 'failed' => false];
        } catch (\Throwable $e) {
            $this->warn('could not read the activity log', $e);

            return ['rows' => [], 'next' => null, 'failed' => true];
        }
    }

    /**
     * Who did something the person may see (of $subject when it is one of theirs), the latest
     * first: the person filter's options, a marker by its label (ActivityActors).
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
        try {
            $people = $this->log->people($subjects);
        } catch (\Throwable $e) {
            $this->warn('could not read the people of the activity log', $e);

            return [];
        }
        $options = [];
        foreach ($people as $by) {
            if ($by !== '') {
                $options[] = ['id' => $by, 'label' => $this->who($by) ?? $by];
            }
        }

        return $options;
    }

    /**
     * An app's own subjects: the declared "{subject}.view" permissions whose subject is no type of
     * the catalog and no reserved name.
     *
     * @return list<string>
     */
    private function appSubjects(): array
    {
        if ($this->declared === null) {
            return [];
        }
        $subjects = [];
        foreach (($this->declared)() as $permission) {
            if (!str_ends_with($permission, '.view')) {
                continue;
            }
            $subject = substr($permission, 0, -strlen('.view'));
            if ($subject === '' || str_contains($subject, '.') || self::reserved($subject) || $this->catalog->get($subject) !== null) {
                continue;
            }
            $subjects[] = $subject;
        }
        sort($subjects);

        return array_values(array_unique($subjects));
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
            $rows[] = $this->row($item, $live[$item->subject] ?? []);
        }

        return $rows;
    }

    /**
     * @param array<string, ?string> $live the subject's ids that still exist (a redirect's: with its current target)
     * @return array<string, mixed>
     */
    private function row(Activity $item, array $live): array
    {
        $listing = self::reserved($item->subject) ? null : $this->catalog->get($item->subject);
        $exists = array_key_exists($item->id, $live);
        [$family, $icon, $name, $url, $drawer] = match (true) {
            $item->subject === self::ASSET => [TypeFamily::Asset, 'ui-library', FolioText::get('folio.activity.asset'), $this->prefix . '/assets/' . rawurlencode($item->id), false],
            $item->subject === self::REDIRECT => [null, 'ui-redirect', FolioText::get('folio.activity.redirect'), $this->prefix . '/redirects/' . rawurlencode($item->id), true],
            $listing === null => [null, 'ui-file', self::subjectLabel($item->subject), null, false], // an app's own subject: no screen to link to
            default => [
                $listing->family,
                $listing->glyph(),
                $this->labels->one($item->subject),
                $listing->singleton
                    ? $this->prefix . '/' . rawurlencode($item->subject)
                    : $this->prefix . '/' . rawurlencode($item->subject) . '/' . rawurlencode($item->id) . '/edit',
                false,
            ],
        };
        $title = $this->title($item);
        $target = $item->subject === self::REDIRECT && $exists ? $live[$item->id] : null;
        if ($target !== null && $target !== '') {
            $title .= ' → ' . $target;
        }

        return [
            'subject' => $item->subject,
            'family' => $family,
            'icon' => $icon,
            'title' => $title,
            'url' => $exists ? $url : null,
            'drawer' => $drawer,
            'what' => implode(' · ', array_filter([$name, $this->fields($item, $listing), $this->kind($item)], static fn (string $part): bool => $part !== '')),
            'who' => $item->by !== null && $item->by !== '' ? $this->who($item->by) : null,
            'at' => $item->at,
            'when' => $this->format->relative($item->at),
            'when_full' => $this->format->date($item->at, 'datetime'),
        ];
    }

    /** The title: Activity::$titleKey translated when it has a text, else the stored title, else the id. */
    private function title(Activity $item): string
    {
        if ($item->titleKey !== null && $item->titleKey !== '') {
            $text = FolioText::get($item->titleKey);
            if ($text !== $item->titleKey && $text !== '') {
                return $text;
            }
        }

        return $item->title !== '' ? $item->title : $item->id;
    }

    /** A person's name (AuthorNames: a registered marker by its label), else as stored. */
    private function who(string $by): ?string
    {
        $label = $this->authors?->label($by) ?? $by;

        return $label !== '' ? $label : null;
    }

    /** "Title, Body edited", "Title, Body and 3 more edited"; '' when nothing says or the kind names no fields. */
    private function fields(Activity $item, ?TypeListing $listing): string
    {
        if ($item->changed === [] || $listing === null || !in_array($item->kind, [ActivityKind::Saved, ActivityKind::Published, ActivityKind::Scheduled], true)) {
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
        if ($item->kind === ActivityKind::Saved && !self::reserved($item->subject) && ($this->publisher?->enabled($item->subject) ?? false)) {
            return FolioText::get('folio.activity.kinds.draft_saved');
        }

        return FolioText::get('folio.activity.kinds.' . $item->kind->value);
    }

    /**
     * Which records, assets and redirects of the rows still exist, by subject: one query per type
     * (its id field IN the ids), the library and the redirects asked once each. A type that can't
     * be read counts as existing (its link stays); an app's subject has no link to check.
     *
     * @param list<Activity> $items
     * @return array<string, array<string, ?string>> subject => id => (a redirect's target text, else null)
     */
    private function live(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[$item->subject][$item->id] = $item->id;
        }
        $live = [];
        foreach ($ids as $subject => $wanted) {
            $subject = (string) $subject;
            $wanted = array_map(strval(...), array_values($wanted));
            $live[$subject] = match (true) {
                $subject === self::ASSET => array_fill_keys($this->assets !== null ? array_map(strval(...), ($this->assets)($wanted)) : [], null),
                $subject === self::REDIRECT => $this->redirects !== null ? ($this->redirects)($wanted) : [],
                self::reserved($subject) || $this->catalog->get($subject) === null => [],
                default => array_fill_keys($this->records($subject, $wanted), null),
            };
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

    private function warn(string $what, \Throwable $e): void
    {
        $message = "Folio: {$what}: {$e->getMessage()}";
        $this->logger !== null ? $this->logger->warning($message, ['exception' => $e]) : error_log($message);
    }

    /**
     * An app's own subject by name: folio.activity.subjects.{subject} (the site's lang/{locale}/folio.php
     * gives it, as any Folio phrase), else its readable name ("work_order": "Work order").
     */
    private static function subjectLabel(string $subject): string
    {
        return Labels::resolve(FolioText::translator(), 'folio.activity.subjects.' . $subject, null) ?? self::humanize($subject);
    }

    /** An app's subject as a name: "purchase_order" reads "Purchase order". */
    private static function humanize(string $subject): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $subject));
    }
}
