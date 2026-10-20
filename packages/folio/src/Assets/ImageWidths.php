<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

/**
 * Which image widths exist, from config/image.php presets:
 * - a preset's "widths" are made when an image is uploaded (eager);
 * - its optional "step" adds widths every step pixels between its smallest and
 *   largest width, made the first time a browser asks for one.
 * Together they are the allow-list for on-demand variants, so nothing outside
 * it is ever generated.
 */
final class ImageWidths
{
    private const array DEFAULT = [480, 768, 1024, 1600];

    /**
     * @param array<string, list<int>> $presets widths per preset, step widths included
     * @param list<int> $eager
     */
    private function __construct(
        private readonly array $presets,
        private readonly array $eager,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $presets config/image.php "presets"
     * @param list<int>|null $folioWidths folio.assets.widths (overrides the eager set)
     */
    public static function fromConfig(array $presets, ?array $folioWidths): self
    {
        $byPreset = [];
        $listed = [];
        foreach ($presets as $name => $preset) {
            $widths = self::ints($preset['widths'] ?? []);
            if ($widths === []) {
                continue;
            }
            $listed = [...$listed, ...$widths];
            $step = (int) ($preset['step'] ?? 0);
            if ($step > 0) {
                for ($w = min($widths) + $step; $w < max($widths); $w += $step) {
                    $widths[] = $w;
                }
            }
            $byPreset[(string) $name] = self::sorted($widths);
        }
        $eager = self::ints($folioWidths ?? []);

        return new self($byPreset, self::sorted($eager !== [] ? $eager : ($listed !== [] ? $listed : self::DEFAULT)));
    }

    /** @return list<int> made at upload */
    public function eager(): array
    {
        return $this->eager;
    }

    /** @return list<int> a preset's widths, step widths included; unknown presets use the eager set */
    public function forPreset(?string $preset): array
    {
        return $preset !== null && isset($this->presets[$preset]) ? $this->presets[$preset] : $this->eager;
    }

    /** @return list<int> every width a page can ask for */
    public function allowed(): array
    {
        return self::sorted([...$this->eager, ...array_merge([], ...array_values($this->presets))]);
    }

    /** @return list<int> the srcset widths for an image: never wider than the original, which joins when narrower */
    public function srcsetWidths(?string $preset, int $originalWidth): array
    {
        $widths = $this->forPreset($preset);
        $out = array_values(array_filter($widths, static fn (int $w): bool => $w <= $originalWidth));
        if ($widths !== [] && $originalWidth < max($widths)) {
            $out[] = $originalWidth;
        }

        return self::sorted($out);
    }

    /** @return list<int> */
    private static function ints(mixed $values): array
    {
        return array_values(array_filter(array_map('intval', is_array($values) ? $values : []), static fn (int $w): bool => $w > 0));
    }

    /** @param list<int> $widths @return list<int> */
    private static function sorted(array $widths): array
    {
        $widths = array_values(array_unique($widths));
        sort($widths);

        return $widths;
    }
}
