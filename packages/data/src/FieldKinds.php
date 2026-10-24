<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Which field types count as free text and which as a relation. Extensions register their own types.
 */
final class FieldKinds
{
    /** @var array<string, true> */
    private array $text = ['string' => true, 'text' => true];

    /** @var array<string, true> */
    private array $relation = ['relation' => true];

    public function text(string ...$types): void
    {
        foreach ($types as $type) {
            $this->text[$type] = true;
        }
    }

    public function relation(string ...$types): void
    {
        foreach ($types as $type) {
            $this->relation[$type] = true;
        }
    }

    public function isText(string $type): bool
    {
        return isset($this->text[$type]);
    }

    public function isRelation(string $type): bool
    {
        return isset($this->relation[$type]);
    }
}
