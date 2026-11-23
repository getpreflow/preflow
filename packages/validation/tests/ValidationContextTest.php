<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\I18n\Translator;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * Since 0.81 validation messages follow the translator, and the translator follows the render
 * context: the context's locale on the public surface, the interface locale on the admin one.
 */
final class ValidationContextTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
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
        // A site's own wiring: the translator on the admin surface only, English on the public one.
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
}
