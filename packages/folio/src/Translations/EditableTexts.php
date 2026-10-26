<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

use Preflow\I18n\DerivedKeys;
use Preflow\I18n\Labels;
use Preflow\I18n\Translator;

/**
 * The texts Settings → Translations offers: every key of an `admin` scope group, and the site's
 * own model labels (group "site": site.types.*, site.fields.*, site.options.*, site.sections.*,
 * which the translator takes overrides for). Never a public group's, never Folio's
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
    ) {}

    /**
     * The groups offered: admin-scope groups, sorted, then "site".
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
        $rows = [];
        foreach (array_unique($keys) as $key) {
            $key = (string) $key;
            if (!$this->offered($key)) {
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

    /** Whether the screen offers the key in this locale (and so may store an override for it). */
    public function editable(string $key, string $locale): bool
    {
        $group = strstr($key, '.', true);

        return $group !== false && isset($this->rows($group, $locale)[$key]);
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
