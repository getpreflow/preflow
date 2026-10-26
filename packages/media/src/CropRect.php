<?php

declare(strict_types=1);

namespace Preflow\Media;

/**
 * A rectangle in percent of an image (of the rotated and flipped original),
 * always inside it: width and height 1-100, x and y so that it does not leave.
 */
final readonly class CropRect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    /** Clamps to the image and rounds to 2 decimals. */
    public static function of(float $x, float $y, float $width, float $height): self
    {
        $width = max(1.0, min(100.0, $width));
        $height = max(1.0, min(100.0, $height));

        return new self(
            round(max(0.0, min(100.0 - $width, $x)), 2),
            round(max(0.0, min(100.0 - $height, $y)), 2),
            round($width, 2),
            round($height, 2),
        );
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    /** Null for anything that is not a rectangle of four numbers. */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!is_numeric($data[$key] ?? null)) {
                return null;
            }
        }

        return self::of((float) $data['x'], (float) $data['y'], (float) $data['width'], (float) $data['height']);
    }

    /** The same area after the image turned clockwise by quarter turns. */
    public function rotated(int $quarterTurns): self
    {
        $rect = $this;
        for ($i = (($quarterTurns % 4) + 4) % 4; $i > 0; $i--) {
            $rect = self::of(100 - $rect->y - $rect->height, $rect->x, $rect->height, $rect->width);
        }

        return $rect;
    }

    /** The same area after the image was mirrored (left-right, or top-bottom when not horizontal). */
    public function mirrored(bool $horizontal): self
    {
        return $horizontal
            ? self::of(100 - $this->x - $this->width, $this->y, $this->width, $this->height)
            : self::of($this->x, 100 - $this->y - $this->height, $this->width, $this->height);
    }

    /** @return array{x: int, y: int, w: int, h: int} the area in a width x height image, inside it and at least 1x1 */
    public function pixels(int $width, int $height): array
    {
        $x = max(0, min($width - 1, (int) round($this->x / 100 * $width)));
        $y = max(0, min($height - 1, (int) round($this->y / 100 * $height)));

        return [
            'x' => $x,
            'y' => $y,
            'w' => max(1, min($width - $x, (int) round($this->width / 100 * $width))),
            'h' => max(1, min($height - $y, (int) round($this->height / 100 * $height))),
        ];
    }

    /**
     * A pixel area of a width x height image in percent of it.
     *
     * @param array{x: int, y: int, w: int, h: int} $pixels
     */
    public static function fromPixels(array $pixels, int $width, int $height): self
    {
        $width = max(1, $width);
        $height = max(1, $height);

        return self::of($pixels['x'] / $width * 100, $pixels['y'] / $height * 100, $pixels['w'] / $width * 100, $pixels['h'] / $height * 100);
    }

    /** True when it covers the whole image (cutting it would change nothing). */
    public function isWhole(): bool
    {
        return $this->width >= 100.0 && $this->height >= 100.0;
    }
}
