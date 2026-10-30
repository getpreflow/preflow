<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * The system part of a request: instructions plus an optional answer template. Kept apart
 * from the prompt because it is identical across a batch, which lets providers cache it.
 */
final readonly class Tuning
{
    /** @param list<string> $instructions */
    public function __construct(public array $instructions = [], public ?string $template = null) {}

    /** Appends instructions; empty ones (after trimming) are dropped. */
    public function with(string ...$instructions): self
    {
        $add = [];
        foreach ($instructions as $instruction) {
            $instruction = trim($instruction);
            if ($instruction !== '') {
                $add[] = $instruction;
            }
        }

        return new self([...$this->instructions, ...$add], $this->template);
    }

    /** Appends the later instructions; the later template wins when it has one. */
    public function merge(self $later): self
    {
        return new self([...$this->instructions, ...$later->instructions], $later->template ?? $this->template);
    }

    public function isEmpty(): bool
    {
        return $this->instructions === [] && ($this->template === null || $this->template === '');
    }

    public function system(): string
    {
        $system = implode("\n\n", $this->instructions);
        if ($this->template !== null && $this->template !== '') {
            $template = "Answer template:\n" . $this->template;
            $system = $system === '' ? $template : $system . "\n\n" . $template;
        }

        return $system;
    }
}
