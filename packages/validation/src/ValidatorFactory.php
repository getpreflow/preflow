<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\I18n\Translator;

/**
 * Makes validators with the app's rules. Given a translator, its validators answer in the
 * translator's language, which follows the render context (since 0.81: the context's locale,
 * or the interface locale on the admin surface); without one (the default) in English.
 *
 * $contextTranslator (since 0.81.0) is asked at each make() when no translator was given: the
 * translator for the current render, or null for English. The application passes one that gives
 * its translator on the admin surface, so the admin's forms answer in its interface language
 * and a site's own forms keep English messages, as before 0.81.
 */
final class ValidatorFactory
{
    /**
     * @param (\Closure(): ?Translator)|null $contextTranslator since 0.81.0: asked at each make()
     *        when no translator was given (the translator for the current render, null for English)
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private ?Translator $translator = null,
        /** @since 0.81.0 */
        private readonly ?\Closure $contextTranslator = null,
    ) {}

    /**
     * Sets the translator of the validators made from now on (null: none) in the current render
     * frame, until it closes; returns the one before. A render in its own frame (an internal
     * render, a mail) starts without it. Outside every render it sets the factory's own
     * translator, which validators made outside renders get and every frame starts from (a call
     * at boot still sets it for every form). Its messages are in the language the render context
     * gives the translator.
     *
     * @deprecated 0.81, removed in 0.83: the admin surface translates the app's validators
     *             (Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale))); for every form, pass the
     *             translator to the constructor
     */
    public function useTranslator(?Translator $translator): ?Translator
    {
        Deprecations::once('Preflow\Validation\ValidatorFactory::useTranslator() is deprecated since 0.81 and is removed in 0.83; use the render context\'s surface (Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale))), or pass the translator to the constructor.');
        $previous = $this->translator();
        if (Contexts::depth() === 0) {
            $this->translator = $translator;
        } else {
            self::frameTranslators()->set[$this] = [$translator];
        }

        return $previous;
    }

    /**
     * @param array<string, list<ValidationRule|string>> $rules
     * @param array<string, mixed> $data
     */
    public function make(array $rules, array $data, mixed $subject = null): Validator
    {
        $translator = $this->translator() ?? ($this->contextTranslator !== null ? ($this->contextTranslator)() : null);

        return new Validator($this->ruleFactory, $rules, $data, $subject, $translator);
    }

    /** The translator given: useTranslator()'s in the current frame, else the factory's own. */
    private function translator(): ?Translator
    {
        if (Contexts::depth() > 0) {
            $set = self::frameTranslators()->set;
            if (isset($set[$this])) {
                return $set[$this][0];
            }
        }

        return $this->translator;
    }

    private static function frameTranslators(): FrameTranslators
    {
        return Contexts::frame()->slot(FrameTranslators::class, static fn (): FrameTranslators => new FrameTranslators());
    }
}
