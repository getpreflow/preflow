<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\Locales;

/** What a structured field needs to know while building a value from a form. */
final readonly class BuildContext
{
    public function __construct(
        public Locales $locales,
        public string $locale,
        public bool $writeUploads,
        public int $depth = 0,
    ) {}

    public function deeper(): self
    {
        return new self($this->locales, $this->locale, $this->writeUploads, $this->depth + 1);
    }
}
