<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

use Preflow\Data\Locales;
use Preflow\I18n\DerivedKeys;
use Preflow\I18n\Labels;
use Preflow\I18n\Translator;

/**
 * The texts Settings → Translations offers: every key of an `admin` scope group, and the site's
 * own model labels (group "site": site.types.*, site.fields.*, site.options.*, site.sections.*,
 * which the translator takes overrides for), and the site's editable groups (config/folio.php
 * translations.editable: texts of public pages and mails, in every language of the site, their
 * overrides keeping the delivered text's placeholders). Never another public group's, never Folio's
 * configuration values (folio.format.*: date patterns, separators, noun case), never a key
 * whose delivered English text has markup, never a key a record id can't carry.
 */
final class EditableTexts
{
    /** The site's own group: only its model labels are offered. */
    public const string SITE = 'site';

    /** Configuration in a language file, not text. */
    private const array CONFIGURATION = ['folio.format.'];

    /** @var array<string, array<string, string|array<string, string>|null>> group => derived key => the model file's text */
    private ?array $derived = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly ?DerivedKeys $derivedKeys = null,
        private readonly ?Locales $siteLocales = null,
    ) {}

    /** A placeholder in a text: ":name". */
    private const string PLACEHOLDER = '/:([A-Za-z_][A-Za-z0-9_]*)/';

    /** Whether the group is one of the site's editable groups (its overrides apply on every request). */
    public function isEditableGroup(string $group): bool
    {
        return in_array($group, $this->translator->alwaysOverridden(), true);
    }

    /**
     * The languages the screen offers for a group: every language of the site for an editable
     * group (two-letter codes, as overrides are stored), else the interface languages given.
     *
     * @param list<string> $interface
     * @return list<string>
     */
    public function locales(string $group, array $interface): array
    {
        if (!$this->isEditableGroup($group)) {
            return $interface;
        }

        return array_values(array_filter(
            ($this->siteLocales ?? Locales::single('en'))->available,
            StoredOverrides::validLocale(...),
        ));
    }

    /**
     * The placeholder names (without the colon) of the key's delivered text in the locale, else
     * of its English text, sorted.
     *
     * @return list<string>
     */
    public function placeholders(string $key, string $locale): array
    {
        $group = (string) strstr($key, '.', true);
        $text = $this->rows($group, $locale)[$key]['delivered'] ?? $this->rows($group, 'en')[$key]['delivered'] ?? null;

        return self::placeholdersIn($text ?? '');
    }

    /**
     * The placeholder names in a text, unique, sorted.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * The groups offered: admin-scope groups, sorted, then "site", then the editable groups, sorted.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = array_values(array_filter(
            $this->translator->groups(),
            fn (string $group): bool => $this->translator->scope($group) === 'admin',
        ));
        $groups[] = self::SITE;
        foreach ($this->translator->alwaysOverridden() as $group) {
            if (!in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * The group's offered texts in a locale, sorted by key: the delivered text (the package's
     * file, else the model file's text: its string or its entry for the locale) and the site's
     * lang/{locale}/{group}.php text when it differs.
     *
     * @return array<string, array{key: string, delivered: ?string, site: ?string}>
     */
    public function rows(string $group, string $locale): array
    {
        if (!in_array($group, $this->groups(), true)) {
            return [];
        }
        $derived = $this->derived()[$group] ?? [];
        $package = $this->translator->packageEntries($group, $locale);
        $site = $this->translator->siteEntries($group, $locale);
        $packageEn = $this->translator->packageEntries($group, 'en');
        $siteEn = $this->translator->siteEntries($group, 'en');

        $keys = array_keys($derived);
        if ($group !== self::SITE) {
            array_push($keys, ...array_keys($packageEn), ...array_keys($siteEn), ...array_keys($package), ...array_keys($site));
        }
        $editableGroup = $this->isEditableGroup($group);
        if ($editableGroup) { // every key of every site language
            foreach ($this->locales($group, []) as $other) {
                array_push($keys, ...array_keys($this->translator->packageEntries($group, $other)), ...array_keys($this->translator->siteEntries($group, $other)));
            }
        }
        $rows = [];
        foreach (array_unique($keys) as $key) {
            $key = (string) $key;
            if (!$this->offered($key)) {
                continue;
            }
            if ($editableGroup) {
                $delivered = $package[$key] ?? $site[$key] ?? null;
                $english = $packageEn[$key] ?? $siteEn[$key] ?? null;
                $siteText = isset($package[$key]) ? ($site[$key] ?? null) : null;
                if (self::markup($english) || self::markup($delivered) || self::markup($siteText)) {
                    continue;
                }
                $rows[$key] = [
                    'key' => $key,
                    'delivered' => $delivered,
                    'site' => $siteText !== null && $siteText !== $delivered ? $siteText : null,
                ];
                continue;
            }
            $model = $derived[$key] ?? null;
            $delivered = $package[$key] ?? Labels::pick($model, $locale, $this->translator->getFallbackLocale());
            $english = $packageEn[$key] ?? $siteEn[$key] ?? Labels::pick($model, 'en', 'en');
            $siteText = $site[$key] ?? null;
            if (self::markup($english) || self::markup($delivered) || self::markup($siteText)) {
                continue;
            }
            $rows[$key] = [
                'key' => $key,
                'delivered' => $delivered,
                'site' => $siteText !== null && $siteText !== $delivered ? $siteText : null,
            ];
        }
        ksort($rows);

        return $rows;
    }

    /**
     * Whether the screen offers the key in this locale (and so may store an override for it).
     * For an editable group the locale must be one of the site's; the interface languages of
     * the other groups are the caller's check (locales()).
     */
    public function editable(string $key, string $locale): bool
    {
        $group = strstr($key, '.', true);
        if ($group === false || ($this->isEditableGroup($group) && !in_array($locale, $this->locales($group, []), true))) {
            return false;
        }

        return isset($this->rows($group, $locale)[$key]);
    }

    /** A text an override may not hold: markup. */
    public static function markup(?string $text): bool
    {
        return $text !== null && strpbrk($text, '<>') !== false;
    }

    private function offered(string $key): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/', $key) !== 1) {
            return false; // the record id "{locale}:{key}" must travel in an export
        }
        foreach (self::CONFIGURATION as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return $this->translator->isOverridable($key);
    }

    /** @return array<string, array<string, string|array<string, string>|null>> */
    private function derived(): array
    {
        if ($this->derived === null) {
            $this->derived = [];
            foreach ($this->derivedKeys?->all() ?? [] as $key => $entry) {
                $group = strstr((string) $key, '.', true);
                if ($group !== false) {
                    $this->derived[$group][(string) $key] = $entry['value'];
                }
            }
        }

        return $this->derived;
    }
}
