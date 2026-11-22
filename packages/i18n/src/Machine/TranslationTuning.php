<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Instructions that go with a text to translate ("formal", "keep it short"): what
 * MachineTranslator::translate() takes. i18n's own type, so a translator needs no other package;
 * AiTranslator turns it into the system part of its AI request. Instructions are trimmed and empty
 * ones dropped, in the constructor as in with().
 *
 * @since 0.83.0
 */
final readonly class TranslationTuning
{
    /** @var list<string> */
    public array $instructions;

    /**
     * @param list<string> $instructions trimmed; empty ones are dropped
     * @since 0.83.0
     */
    public function __construct(array $instructions = [])
    {
        $this->instructions = self::clean($instructions);
    }

    /**
     * Appends instructions; empty ones (after trimming) are dropped.
     *
     * @since 0.83.0
     */
    public function with(string ...$instructions): self
    {
        return new self([...$this->instructions, ...array_values($instructions)]);
    }

    /** @since 0.83.0 */
    public function isEmpty(): bool
    {
        return $this->instructions === [];
    }

    /**
     * @param array<array-key, string> $instructions
     * @return list<string>
     */
    private static function clean(array $instructions): array
    {
        $clean = [];
        foreach ($instructions as $instruction) {
            $instruction = trim($instruction);
            if ($instruction !== '') {
                $clean[] = $instruction;
            }
        }

        return $clean;
    }
}
