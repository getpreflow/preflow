<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

/**
 * Texts several pages of one locale share (titles, descriptions), compared with
 * spaces collapsed and in any case. Keeps what it saw as plain data, for a run
 * that continues later.
 *
 * @internal
 * @since 0.54
 */
final class DuplicateTexts
{
    /** @var array<string, array<array-key, array{t: string, u: list<string>}>> locale => normalised text => [text, URLs] */
    private array $seen = [];

    public function add(string $locale, string $text, string $url): void
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return;
        }
        $key = mb_strtolower($text);
        $this->seen[$locale][$key]['t'] ??= $text;
        $this->seen[$locale][$key]['u'][] = $url;
    }

    /**
     * Each page whose text another page of its locale has too, in the order they were seen.
     *
     * @return list<array{locale: string, text: string, url: string, others: list<string>}>
     */
    public function duplicates(): array
    {
        $out = [];
        foreach ($this->seen as $locale => $texts) {
            foreach ($texts as $entry) {
                $urls = array_values(array_unique($entry['u']));
                if (count($urls) < 2) {
                    continue;
                }
                foreach ($urls as $url) {
                    $out[] = [
                        'locale' => (string) $locale,
                        'text' => $entry['t'],
                        'url' => $url,
                        'others' => array_values(array_diff($urls, [$url])),
                    ];
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<array-key, array{t: string, u: list<string>}>> */
    public function state(): array
    {
        return $this->seen;
    }

    /** @param array<array-key, mixed> $state what state() gave */
    public function restore(array $state): void
    {
        $this->seen = [];
        foreach ($state as $locale => $texts) {
            foreach (is_array($texts) ? $texts : [] as $key => $entry) {
                if (is_array($entry) && is_string($entry['t'] ?? null) && is_array($entry['u'] ?? null)) {
                    $this->seen[(string) $locale][$key] = ['t' => $entry['t'], 'u' => array_values(array_map('strval', $entry['u']))];
                }
            }
        }
    }

    /**
     * How a finding names the other pages: "https://…/a", or "3 other pages, e.g. https://…/a".
     *
     * @param list<string> $others
     */
    public static function others(array $others): string
    {
        $count = count($others);

        return $count === 1 ? $others[0] : "{$count} other pages, e.g. {$others[0]}";
    }
}
