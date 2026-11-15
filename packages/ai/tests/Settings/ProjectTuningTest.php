<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Settings;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\Settings\ProjectTuning;

/** The project's instructions for every AI request: the Settings → AI edit, else config/ai.php. */
final class ProjectTuningTest extends TestCase
{
    /** @param array<mixed> $tuning */
    private static function store(array $tuning): AiSettingsStore
    {
        return new class ($tuning) implements AiSettingsStore {
            /** @param array<mixed> $tuning */
            public function __construct(private array $tuning) {}

            public function get(string $section): array
            {
                return $section === 'tuning' ? $this->tuning : [];
            }

            public function put(string $section, array $data): void {}
        };
    }

    public function test_config_default_when_nothing_is_stored(): void
    {
        $project = new ProjectTuning(['tuning' => ['  Our product is "Foo".  ', '', 3]], self::store([]));

        $this->assertSame(['Our product is "Foo".'], $project->defaults());
        $this->assertSame([], $project->edited());
        $this->assertSame(['Our product is "Foo".'], $project->instructions());
        $this->assertSame(['Our product is "Foo".'], $project->tuning()->instructions);
    }

    public function test_the_stored_edit_replaces_the_default(): void
    {
        $project = new ProjectTuning(['tuning' => 'Default.'], self::store(['project' => ['Edited.'], 'lang:de' => ['Not this.']]));

        $this->assertSame(['Edited.'], $project->instructions());
        $this->assertSame(['Default.'], $project->defaults());
    }

    public function test_an_empty_edit_brings_the_default_back(): void
    {
        $this->assertSame(['Default.'], (new ProjectTuning(['tuning' => ['Default.']], self::store(['project' => ['  ']])))->instructions());
        $this->assertSame([], (new ProjectTuning([], self::store([])))->instructions());
    }
}
