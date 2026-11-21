<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\I18n\Translator;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * Since 0.81 validation messages follow the translator, and the translator follows the render
 * context: the context's locale on the public surface, the interface locale on the admin one.
 * useTranslator() is deprecated.
 */
final class ValidationContextTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
        Deprecations::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        Deprecations::reset();
    }

    private static function translator(): Translator
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('validation', dirname(__DIR__) . '/lang');

        return $translator;
    }

    private static function visit(): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', '/'), new Site('https://site.test', ['en', 'de', 'fr'], 'en'), new SystemClock());
    }

    /** @return list<string> */
    private static function required(ValidatorFactory $factory): array
    {
        return $factory->make(['f' => ['required']], [])->validate()->fieldErrors('f');
    }

    public function test_validation_messages_follow_the_context_locale(): void
    {
        $factory = new ValidatorFactory(new RuleFactory(), self::translator());

        $seen = Contexts::run(self::visit()->in('de'), static function () use ($factory): array {
            $seen = [self::required($factory)];
            $seen[] = Contexts::within(Contexts::current()->admin('fr'), static fn (): array => self::required($factory));
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('en'));
            $seen[] = self::required($factory);

            return $seen;
        });

        $this->assertSame([['Dieses Feld ist erforderlich.'], ['Ce champ est obligatoire.'], ['This field is required.']], $seen);
    }

    public function test_a_context_translator_is_asked_for_each_validator(): void
    {
        $translator = self::translator();
        // As the application wires it: the translator on the admin surface, English on the public one.
        $factory = new ValidatorFactory(
            new RuleFactory(),
            contextTranslator: static fn (): ?Translator => Contexts::current()->surface->admin ? $translator : null,
        );

        $seen = Contexts::run(self::visit()->in('de'), static fn (): array => [
            self::required($factory),
            Contexts::within(Contexts::current()->admin('fr'), static fn (): array => self::required($factory)),
        ]);

        $this->assertSame([['This field is required.'], ['Ce champ est obligatoire.']], $seen);
    }

    public function test_use_translator_is_deprecated(): void
    {
        $factory = new ValidatorFactory(new RuleFactory());
        $translator = self::translator();
        $messages = [];
        set_error_handler(static function (int $no, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        });
        try {
            $this->assertNull($factory->useTranslator($translator));
            $german = Contexts::run(self::visit()->in('de'), static fn (): array => self::required($factory));
            $this->assertSame($translator, $factory->useTranslator(null));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['Dieses Feld ist erforderlich.'], $german, 'the translator it sets answers in the context\'s locale');
        $this->assertSame(['This field is required.'], self::required($factory));
        $this->assertSame(
            ['Preflow\Validation\ValidatorFactory::useTranslator() is deprecated since 0.81 and is removed in 0.83; use the render context\'s surface (Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale))), or pass the translator to the constructor.'],
            array_values(array_unique($messages)),
        );
    }

    /** Inside a render useTranslator() sets the translator for its frame only; a nested render starts from the factory's own. */
    public function test_use_translator_in_a_render_ends_with_its_frame(): void
    {
        $factory = new ValidatorFactory(new RuleFactory());
        $translator = self::translator();
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);
        try {
            $seen = Contexts::run(self::visit()->in('de'), static function () use ($factory, $translator): array {
                $seen = [self::required($factory)];
                $previous = $factory->useTranslator($translator);
                $seen[] = self::required($factory);
                $seen[] = Contexts::run(self::visit()->in('de'), static fn (): array => self::required($factory));
                $seen[] = $factory->useTranslator(null) === $translator && $previous === null ? ['returned'] : ['wrong'];

                return $seen;
            });
            $after = Contexts::run(self::visit()->in('de'), static fn (): array => self::required($factory));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([
            ['This field is required.'],
            ['Dieses Feld ist erforderlich.'],
            ['This field is required.'],
            ['returned'],
        ], $seen, 'none, then the frame\'s, a nested render without it; useTranslator() returns the frame\'s');
        $this->assertSame(['This field is required.'], $after, 'the next render starts without it');
        $this->assertSame(['This field is required.'], self::required($factory), 'and so do validators outside every render');
    }

    /** The documented scope: it outlasts an enclosing within() (to the end of the frame); a part() frame starts from the factory's own. */
    public function test_use_translator_outlasts_within_and_skips_part_frames(): void
    {
        $factory = new ValidatorFactory(new RuleFactory());
        $translator = self::translator();
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);
        try {
            $seen = Contexts::run(self::visit()->in('de'), static function () use ($factory, $translator): array {
                Contexts::within(Contexts::current(), static fn (): ?Translator => $factory->useTranslator($translator));

                return [
                    self::required($factory),
                    Contexts::part(Contexts::current(), static fn (): array => self::required($factory)),
                ];
            });
        } finally {
            restore_error_handler();
        }

        $this->assertSame([['Dieses Feld ist erforderlich.'], ['This field is required.']], $seen);
    }
}
