<?php

declare(strict_types=1);

namespace Preflow\Ai\Settings;

use Preflow\Ai\Tuning;

/**
 * The project's own instructions for every AI request (translations, drafts, …): the edit made in
 * Settings → AI (the stored "tuning" section's "project" level) when there is one, else
 * config/ai.php `tuning`. The one place that rule lives; translation tuning builds on it.
 *
 * @since 0.77
 */
final class ProjectTuning
{
    /** The settings-store section that holds tuning edits, by level key. */
    public const string SECTION = 'tuning';

    /** The level key of the project's instructions in that section. */
    public const string LEVEL = 'project';

    /** @param array<string, mixed> $aiConfig the config/ai.php array */
    public function __construct(
        private readonly array $aiConfig,
        private readonly AiSettingsStore $store,
    ) {}

    /** @return list<string> config/ai.php `tuning` */
    public function defaults(): array
    {
        return self::lines($this->aiConfig['tuning'] ?? []);
    }

    /** @return list<string> the stored edit, [] when there is none */
    public function edited(): array
    {
        return self::lines($this->store->get(self::SECTION)[self::LEVEL] ?? []);
    }

    /** @return list<string> the edit when there is one, else the defaults */
    public function instructions(): array
    {
        $edited = $this->edited();

        return $edited !== [] ? $edited : $this->defaults();
    }

    public function tuning(): Tuning
    {
        return new Tuning($this->instructions());
    }

    /**
     * A string or a list of strings as trimmed, non-empty lines.
     *
     * @return list<string>
     */
    public static function lines(mixed $value): array
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
