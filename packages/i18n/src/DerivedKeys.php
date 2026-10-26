<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * Translation keys that come from other files than language files, with the text those
 * files give for them: e.g. a model file's labels (site.types.app.label => "Apps", or a map
 * per language). Packages that read such files add a source (Folio adds its types' model
 * files); translations:check checks them. A key of a package group (one with a scope) needs
 * a text in every delivered language, from a language file or the source's map; a key of the
 * site's own group never does, since the source's plain text is a valid fallback.
 */
final class DerivedKeys
{
    /** @var list<array{0: string, 1: \Closure(): array<string, string|array<string, string>|null>}> */
    private array $sources = [];

    /**
     * @param string                                                  $what   where the keys come from (shown by translations:check)
     * @param \Closure(): array<string, string|array<string, string>|null> $keys key => the source's text (asked when checked)
     */
    public function add(string $what, \Closure $keys): void
    {
        $this->sources[] = [$what, $keys];
    }

    /**
     * Every key with its text and where it comes from; the first source naming a key wins.
     *
     * @return array<string, array{value: string|array<string, string>|null, source: string}>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->sources as [$what, $keys]) {
            foreach ($keys() as $key => $value) {
                $all[(string) $key] ??= ['value' => $value, 'source' => $what];
            }
        }

        return $all;
    }
}
