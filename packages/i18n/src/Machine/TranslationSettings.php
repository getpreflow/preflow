<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\Tuning;

/**
 * The translation instructions and glossary in effect: defaults from config/ai.php (and, for
 * fields, the model JSON), with edits made in the admin stored apart from them.
 *
 * Instructions are layered. Every level has a key ('project', 'lang:{locale}', '{type}',
 * '{type}.{field}', '{type}.{field}:{kind}'); a stored edit replaces the default of that one
 * level and leaves the others alone. Saving an empty list drops the edit, which brings the
 * default back; a default cannot be blanked out from the admin.
 */
final class TranslationSettings
{
    private const TUNING = 'tuning';
    private const GLOSSARY = 'glossary';

    /** @param array<string,mixed> $aiConfig the config/ai.php array */
    public function __construct(
        private readonly array $aiConfig,
        private readonly AiSettingsStore $store,
    ) {}

    /** System levels in order: project, then the target language, then the content type. */
    public function tuning(string $to, ?string $type = null): Tuning
    {
        $stored = $this->stored();
        $keys = ['project', 'lang:' . $to];
        if ($type !== null && $type !== '') {
            $keys[] = $type;
        }

        $instructions = [];
        foreach ($keys as $key) {
            array_push($instructions, ...$this->level($key, $this->configDefault($key), $stored));
        }

        return new Tuning($instructions);
    }

    /**
     * Instructions for one field, then for its kind of unit, joined by line breaks.
     *
     * @param array<string,mixed> $fieldAi the field's "ai" config in the model JSON:
     *        ['tuning' => string|list<string>, 'kinds' => [kind => string|list<string>]]
     */
    public function unitTuning(string $type, string $field, ?string $kind, array $fieldAi = []): ?string
    {
        $stored = $this->stored();
        $fieldKey = $type . '.' . $field;
        $lines = $this->level($fieldKey, self::lines($fieldAi['tuning'] ?? []), $stored);

        if ($kind !== null && $kind !== '') {
            $kinds = is_array($fieldAi['kinds'] ?? null) ? $fieldAi['kinds'] : [];
            array_push($lines, ...$this->level($fieldKey . ':' . $kind, self::lines($kinds[$kind] ?? []), $stored));
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** The stored glossary when it has rows, otherwise the one in config. */
    public function glossary(): Glossary
    {
        $stored = $this->store->get(self::GLOSSARY);
        if ($stored !== []) {
            return Glossary::fromArray($this->rows($stored));
        }

        $translation = $this->aiConfig['translation'] ?? [];

        return Glossary::fromArray($this->rows(is_array($translation) ? ($translation['glossary'] ?? []) : []));
    }

    /**
     * The regular expressions of the project's own tokens that a machine translation must keep
     * (config/ai.php translation.protected_tokens: a list, or one pattern as a string). See
     * MarkupCheck for how they are compared. Blank and non-string entries are skipped.
     *
     * @return list<string>
     * @throws \InvalidArgumentException when a pattern is not a valid regular expression: a broken
     *                                   pattern would otherwise let every token through unchecked
     */
    public function protectedTokens(): array
    {
        $patterns = $this->translationConfig()['protected_tokens'] ?? [];
        $out = [];
        foreach (is_array($patterns) ? $patterns : [$patterns] as $i => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            $pattern = trim($pattern);
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException(sprintf(
                    'config/ai.php translation.protected_tokens[%s]: "%s" is not a valid regular expression (%s).',
                    $i,
                    $pattern,
                    preg_last_error_msg(),
                ));
            }
            $out[] = $pattern;
        }

        return $out;
    }

    /**
     * Every level key with its instructions, for the Settings screen: the config defaults with
     * stored edits applied, plus stored levels that have no default (field levels live in the
     * model JSON, so only edited ones show up here).
     *
     * @return array<string, list<string>>
     */
    public function levels(): array
    {
        $stored = $this->stored();
        $levels = ['project' => $this->level('project', $this->configDefault('project'), $stored)];

        $translation = $this->translationConfig();
        foreach (['lang:' => 'languages', '' => 'types'] as $prefix => $section) {
            $rows = is_array($translation[$section] ?? null) ? $translation[$section] : [];
            foreach ($rows as $name => $_) {
                $key = $prefix . $name;
                $levels[$key] = $this->level($key, $this->configDefault($key), $stored);
            }
        }
        foreach ($stored as $key => $_) {
            $levels[$key] ??= $this->level($key, [], $stored);
        }

        return $levels;
    }

    /**
     * What the admin stored for each level (without the config defaults), so a screen can show
     * the edit as the value and the default as its placeholder.
     *
     * @return array<string, list<string>>
     */
    public function edited(): array
    {
        return $this->stored();
    }

    /**
     * The config/ai.php default of one level; empty for field and kind levels, whose defaults live
     * in the model JSON.
     *
     * @return list<string>
     */
    public function defaultFor(string $levelKey): array
    {
        return str_contains($levelKey, '.') ? [] : $this->configDefault($levelKey);
    }

    /**
     * @param list<string> $instructions an empty list (after trimming) removes the stored edit
     */
    public function saveLevel(string $levelKey, array $instructions): void
    {
        $stored = $this->stored();
        $instructions = self::lines($instructions);
        if ($instructions === []) {
            unset($stored[$levelKey]);
        } else {
            $stored[$levelKey] = $instructions;
        }

        $this->store->put(self::TUNING, $stored);
    }

    public function saveGlossary(Glossary $g): void
    {
        $this->store->put(self::GLOSSARY, $g->toArray());
    }

    /**
     * @param array<string,list<string>> $stored
     * @param list<string> $default
     * @return list<string>
     */
    private function level(string $key, array $default, array $stored): array
    {
        return ($stored[$key] ?? []) !== [] ? $stored[$key] : $default;
    }

    /** @return list<string> the config/ai.php default of a system level */
    private function configDefault(string $key): array
    {
        if ($key === 'project') {
            return self::lines($this->aiConfig['tuning'] ?? []);
        }

        $translation = $this->translationConfig();
        if (str_starts_with($key, 'lang:')) {
            $languages = is_array($translation['languages'] ?? null) ? $translation['languages'] : [];

            return self::lines($languages[substr($key, 5)] ?? []);
        }

        $types = is_array($translation['types'] ?? null) ? $translation['types'] : [];

        return self::lines($types[$key] ?? []);
    }

    /** @return array<string,mixed> */
    private function translationConfig(): array
    {
        $translation = $this->aiConfig['translation'] ?? [];

        return is_array($translation) ? $translation : [];
    }

    /** @return array<string,list<string>> stored edits, level key => instructions */
    private function stored(): array
    {
        $out = [];
        foreach ($this->store->get(self::TUNING) as $key => $value) {
            $lines = self::lines($value);
            if ($lines !== []) {
                $out[(string) $key] = $lines;
            }
        }

        return $out;
    }

    /**
     * @param mixed $rows
     * @return list<array<string,mixed>>
     */
    private function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * A string or a list of strings as trimmed, non-empty lines.
     *
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [$value] as $line) {
            if (is_string($line) && trim($line) !== '') {
                $out[] = trim($line);
            }
        }

        return $out;
    }
}
