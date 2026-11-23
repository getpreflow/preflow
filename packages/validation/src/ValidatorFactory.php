<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\I18n\Translator;

/**
 * Makes validators with the app's rules. Given a translator, its validators answer in the
 * translator's language, which follows the render context (since 0.81: the context's locale,
 * or the interface locale on the admin surface); without one (the default) in English.
 *
 * $contextTranslator (since 0.81.0) is asked at each make() when no translator was given: the
 * translator for the current render, or null for English. The application passes one that gives
 * its translator inside any render (since 0.84.2): a site's own forms answer in the visitor's
 * locale on a public page, the admin's in its interface language, with no wiring. Outside every
 * render they stay English. A translator given to the constructor wins.
 */
final class ValidatorFactory
{
    /**
     * @param (\Closure(): ?Translator)|null $contextTranslator since 0.81.0: asked at each make()
     *        when no translator was given (the translator for the current render, null for English)
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly ?Translator $translator = null,
        /** @since 0.81.0 */
        private readonly ?\Closure $contextTranslator = null,
    ) {}

    /**
     * @param array<string, list<ValidationRule|string>> $rules
     * @param array<string, mixed> $data
     */
    public function make(array $rules, array $data, mixed $subject = null): Validator
    {
        $translator = $this->translator ?? ($this->contextTranslator !== null ? ($this->contextTranslator)() : null);

        return new Validator($this->ruleFactory, $rules, $data, $subject, $translator);
    }
}
