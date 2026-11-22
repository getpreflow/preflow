<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Instructions that go with a text to translate ("formal", "keep it short"): what
 * MachineTranslator::translate() takes. i18n's own type, so a translator needs no other package;
 * AiTranslator turns it into the system part of its AI request.
 *
 * @since 0.83.0
 */
final readonly class TranslationTuning
{
    /**
     * @param list<string> $instructions
     * @since 0.83.0
     */
    public function __construct(public array $instructions = []) {}

    /**
     * Appends instructions; empty ones (after trimming) are dropped.
     *
     * @since 0.83.0
     */
    public function with(string ...$instructions): self
    {
        $add = [];
        foreach ($instructions as $instruction) {
            $instruction = trim($instruction);
            if ($instruction !== '') {
                $add[] = $instruction;
            }
        }

        return new self([...$this->instructions, ...$add]);
    }

    /** @since 0.83.0 */
    public function isEmpty(): bool
    {
        return $this->instructions === [];
    }
}
