<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/**
 * The presets of config/image.php as formats, checked when built so a bad one
 * fails at boot. "fit" is cover, contain or max (the default); a box needs a
 * positive width and height and a name of lower-case letters, digits and
 * dashes that is not one of the file-name parts (edit, preview, oriented).
 */
final class Formats
{
    private const array FITS = ['cover', 'contain', 'max'];

    private const array RESERVED = ['edit', 'preview', 'oriented'];

    /** @param array<string, Format> $formats */
    private function __construct(private readonly array $formats) {}

    /** @param array<string, mixed> $presets config/image.php "presets" */
    public static function fromConfig(array $presets): self
    {
        $formats = [];
        foreach ($presets as $name => $preset) {
            $name = (string) $name;
            $preset = is_array($preset) ? $preset : [];
            $fit = $preset['fit'] ?? 'max';
            if (!is_string($fit) || !in_array($fit, self::FITS, true)) {
                throw new \InvalidArgumentException('config/image.php: preset "' . $name . '" has fit "' . (is_scalar($fit) ? $fit : gettype($fit)) . '"; use cover, contain or max.');
            }
            $width = (int) ($preset['width'] ?? 0);
            $height = isset($preset['height']) && (int) $preset['height'] > 0 ? (int) $preset['height'] : null;
            if ($fit !== 'max') {
                if ($width < 1 || $height === null) {
                    throw new \InvalidArgumentException('config/image.php: preset "' . $name . '" is a ' . $fit . ' box and needs a positive width and height.');
                }
                if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1 || in_array($name, self::RESERVED, true)
                    || preg_match('/^(edit|preview|oriented)-|^v\d+(-|$)/', $name) === 1) {
                    throw new \InvalidArgumentException('config/image.php: the box preset name "' . $name . '" is not allowed; use lower-case letters, digits and dashes, starting with a letter, and not edit, preview or oriented (nor starting with edit-, preview-, oriented- or v and a number).');
                }
            }
            $widths = array_values(array_filter(array_map('intval', is_array($preset['widths'] ?? null) ? $preset['widths'] : []), static fn (int $w): bool => $w > 0));
            $formats[$name] = new Format($name, $width, $height, $fit, $widths);
        }

        return new self($formats);
    }

    public function get(string $name): ?Format
    {
        return $this->formats[$name] ?? null;
    }

    /** @return array<string, Format> the cover and contain presets */
    public function boxes(): array
    {
        return array_filter($this->formats, static fn (Format $f): bool => $f->isBox());
    }

    /** The first 8 hex characters of the sha256 of the inputs as JSON: the cache key in a rendered file's name. */
    public static function key(array $inputs): string
    {
        return substr(hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)), 0, 8);
    }
}
