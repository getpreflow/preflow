<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\I18n\Translator;

/**
 * Makes validators with the app's rules. Given a translator, its validators answer in the
 * translator's language; without one (the default) in English.
 */
final class ValidatorFactory
{
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private ?Translator $translator = null,
    ) {}

    /**
     * Sets the translator of the validators made from now on (null: English); returns the one
     * before, so a caller (Folio, for an admin request) puts it back when done.
     */
    public function useTranslator(?Translator $translator): ?Translator
    {
        $previous = $this->translator;
        $this->translator = $translator;

        return $previous;
    }

    /**
     * @param array<string, list<ValidationRule|string>> $rules
     * @param array<string, mixed> $data
     */
    public function make(array $rules, array $data, mixed $subject = null): Validator
    {
        return new Validator($this->ruleFactory, $rules, $data, $subject, $this->translator);
    }
}
