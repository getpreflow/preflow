<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\LocaleUrls;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\TranslationOverrides;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Since 0.81 the translator reads its locale and its overrides switch from the render context
 * (Contexts::current()): the public surface's texts in the context's locale, the admin surface's
 * in its interface locale with the overrides it asks for. setLocale() and useOverrides() are
 * deprecated shims that narrow the current frame. LocaleMiddleware narrows the frame to the
 * locale and the prefix the URL carried; nothing is put back by hand.
 */
final class LocaleContextTest extends TestCase
{
    private string $lang;

    protected function setUp(): void
    {
        Contexts::reset();
        Deprecations::reset();
        $this->lang = sys_get_temp_dir() . '/preflow_locale_ctx_' . bin2hex(random_bytes(4));
        foreach (['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'] as $locale => $hi) {
            @mkdir($this->lang . '/' . $locale, 0777, true);
            // Counts its own loads: the "loader" of the catalogue-cache test.
            file_put_contents(
                $this->lang . '/' . $locale . '/site.php',
                "<?php \$GLOBALS['preflow_locale_ctx_loads']['{$locale}'] = (\$GLOBALS['preflow_locale_ctx_loads']['{$locale}'] ?? 0) + 1;\nreturn ['hi' => '{$hi}'];",
            );
        }
        $GLOBALS['preflow_locale_ctx_loads'] = [];
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        Deprecations::reset();
        foreach (glob($this->lang . '/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->lang . '/*') ?: [] as $dir) {
            rmdir($dir);
        }
        @rmdir($this->lang);
        unset($GLOBALS['preflow_locale_ctx_loads']);
    }

    private function translator(): Translator
    {
        return new Translator($this->lang, 'en', 'en');
    }

    private static function site(?LocaleUrls $urls = null): Site
    {
        return new Site('https://site.test', ['en', 'de', 'fr'], 'en', $urls);
    }

    private static function visit(string $path = '/', ?Site $site = null): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', $path), $site ?? self::site(), new SystemClock());
    }

    /** Overrides that answer every key with "Override". */
    private static function overrides(): TranslationOverrides
    {
        return new class implements TranslationOverrides {
            public function get(string $locale, string $key): ?string
            {
                return 'Override ' . $locale;
            }
        };
    }

    /**
     * Runs $fn and returns the E_USER_DEPRECATED messages it raised.
     *
     * @return list<string>
     */
    private static function deprecations(\Closure $fn): array
    {
        $messages = [];
        set_error_handler(static function (int $no, string $message) use (&$messages): bool {
            if ($no === E_USER_DEPRECATED) {
                $messages[] = $message;
            }

            return true;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    public function test_translator_follows_the_current_frame_locale(): void
    {
        $t = $this->translator();

        Contexts::run(self::visit()->in('de'), function () use ($t): void {
            $this->assertSame('de', $t->getLocale());
            $this->assertSame('Hallo', $t->get('site.hi'));

            Contexts::run(self::visit()->in('fr'), function () use ($t): void {
                $this->assertSame('Bonjour', $t->get('site.hi'), 'a nested frame has its own locale');
            });
            $this->assertSame('Hallo', $t->get('site.hi'), 'the outer frame is back');

            Contexts::within(Contexts::current()->in('en'), function () use ($t): void {
                $this->assertSame('Hello', $t->get('site.hi'));
            });
            $this->assertSame('Hallo', $t->get('site.hi'));

            // The admin surface: the admin's own texts in its interface locale.
            Contexts::within(Contexts::current()->admin('fr'), function () use ($t): void {
                $this->assertSame('fr', $t->getLocale());
                $this->assertSame('de', Contexts::current()->locale, 'the content locale stays');
                $this->assertSame('Bonjour', $t->get('site.hi'));
            });
        });

        // Outside every render: the translator's own default locale.
        $this->assertSame('en', $t->getLocale());
        $this->assertSame('Hello', $t->get('site.hi'));
    }

    public function test_internal_render_from_an_admin_frame_uses_visitor_texts_without_overrides(): void
    {
        $t = $this->translator();
        $t->setOverrides(self::overrides());
        $t->setScopes(['site' => 'admin']);

        Contexts::run(self::visit()->admin('de'), function () use ($t): void {
            $this->assertSame('Override de', $t->get('site.hi'), 'the admin sees its overrides');
            $this->assertTrue($t->usesOverrides());

            $seen = Contexts::run(RenderContext::internal(Purpose::Audit, self::site(), new SystemClock(), '/about'), static fn (): array => [$t->getLocale(), $t->get('site.hi'), $t->usesOverrides()]);
            $this->assertSame(['en', 'Hello', false], $seen, 'the audit render shows what visitors get');

            $this->assertSame(['de', 'Override de', true], [$t->getLocale(), $t->get('site.hi'), $t->usesOverrides()], 'the admin frame is unchanged');
        });
    }

    public function test_set_locale_is_a_deprecated_shim_bound_to_its_frame(): void
    {
        $t = $this->translator();

        $messages = self::deprecations(function () use ($t): void {
            Contexts::run(self::visit(), function () use ($t): void {
                for ($i = 0; $i < 3; $i++) {
                    $t->setLocale('de');
                }
                $this->assertSame('de', Contexts::current()->locale, 'the shim narrows the frame');
                $this->assertSame('Hallo', $t->get('site.hi'));

                Contexts::run(self::visit(), function () use ($t): void {
                    $t->setLocale('fr');
                    $this->assertSame('Bonjour', $t->get('site.hi'));
                });
                $this->assertSame('de', $t->getLocale(), 'a nested frame\'s setLocale() stays in that frame');

                // On the admin surface it sets the interface locale (what getLocale() gives there).
                Contexts::within(Contexts::current()->admin('en'), function () use ($t): void {
                    $t->setLocale('fr');
                    $this->assertSame('fr', Contexts::current()->surface->uiLocale);
                    $this->assertSame('de', Contexts::current()->locale);
                });
            });
            Contexts::run(self::visit(), function () use ($t): void {
                $this->assertSame('en', $t->getLocale(), 'the next frame starts from its own context');
            });
        });

        $this->assertContains('Preflow\I18n\Translator::setLocale() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->in($locale)).', $messages);
        $this->assertCount(3, $messages, 'once per call site, not per call');

        // Outside every render it sets the translator's own default (a script, a unit test).
        self::deprecations(static fn () => $t->setLocale('de'));
        $this->assertSame('Hallo', $t->get('site.hi'));
        Contexts::run(self::visit(), function () use ($t): void {
            $this->assertSame('en', $t->getLocale(), 'a render reads its context, not that default');
        });
    }

    public function test_use_overrides_is_a_deprecated_shim_bound_to_its_frame(): void
    {
        $t = $this->translator();
        $t->setOverrides(self::overrides());
        $t->setScopes(['site' => 'admin']);

        $messages = self::deprecations(function () use ($t): void {
            Contexts::run(self::visit()->in('de'), function () use ($t): void {
                $this->assertFalse($t->usesOverrides());
                $this->assertSame('Hallo', $t->get('site.hi'));

                $t->useOverrides(true);
                $surface = Contexts::current()->surface;
                $this->assertTrue($surface->admin && $surface->overrides, 'the shim narrows to the admin surface with overrides');
                $this->assertSame('de', $surface->uiLocale, 'in the locale the texts were in');
                $this->assertTrue($t->usesOverrides());
                $this->assertSame('Override de', $t->get('site.hi'));

                $t->useOverrides(false);
                $this->assertFalse($t->usesOverrides());
                $this->assertSame('Hallo', $t->get('site.hi'));
                $t->useOverrides(true);
            });
            Contexts::run(self::visit(), function () use ($t): void {
                $this->assertFalse($t->usesOverrides(), 'the next frame starts public');
                $this->assertSame('Hello', $t->get('site.hi'));
            });
        });

        $this->assertContains('Preflow\I18n\Translator::useOverrides() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale, $overrides)).', $messages);

        // Outside every render it switches the translator's own default.
        self::deprecations(static fn () => $t->useOverrides(true));
        $this->assertSame('Override en', $t->get('site.hi'));
    }

    public function test_locale_middleware_narrows_and_never_restores_by_hand(): void
    {
        $t = $this->translator();
        $mw = new LocaleMiddleware($t, ['en', 'de'], 'en', 'prefix');
        $seen = [];
        $handler = new class ($seen, $t) implements RequestHandlerInterface {
            /** @param list<array<string, string>> $seen */
            public function __construct(private array &$seen, private readonly Translator $t) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $ctx = Contexts::current();
                $this->seen[] = ['locale' => $ctx->locale, 'path' => $ctx->request?->path ?? '', 'translator' => $this->t->getLocale()];

                return new Response(200);
            }
        };

        $after = Contexts::run(self::visit('/de/x'), static function () use ($mw, $handler): string {
            $mw->process(new ServerRequest('GET', '/de/x'), $handler);

            return Contexts::current()->locale;
        });
        Contexts::run(self::visit('/x'), static fn () => $mw->process(new ServerRequest('GET', '/x'), $handler));

        $this->assertSame([
            ['locale' => 'de', 'path' => '/x', 'translator' => 'de'],
            ['locale' => 'en', 'path' => '/x', 'translator' => 'en'],
        ], $seen);
        $this->assertSame('de', $after, 'narrowed for the rest of the frame, not put back');
        $this->assertSame('en', $t->getLocale(), 'nothing outside the frames changed');
    }

    public function test_an_internal_render_without_a_prefix_keeps_its_callers_locale(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Contexts::current()->locale . '|' . $request->getAttribute('locale'));
            }
        };
        $none = new LocaleMiddleware($this->translator(), ['en', 'de'], 'en', 'none');
        $prefix = new LocaleMiddleware($this->translator(), ['en', 'de'], 'en', 'prefix');
        $render = static fn (LocaleMiddleware $mw, string $path, string $locale): string => (string) Contexts::run(
            RenderContext::internal(Purpose::Geo, self::site(), new SystemClock(), $path)->in($locale),
            static fn () => $mw->process(new ServerRequest('GET', $path), $handler),
        )->getBody();

        // A site whose URLs carry no locale: the page is rendered in the locale it was asked for.
        $this->assertSame('de|de', $render($none, '/about', 'de'));
        // A URL prefix still decides.
        $this->assertSame('en|en', $render($prefix, '/en/about', 'de'));
        $this->assertSame('de|de', $render($prefix, '/about', 'de'));
        // A visitor's request still goes by the cookie, Accept-Language or the default.
        $visit = Contexts::run(self::visit('/about')->in('de'), static fn () => $none->process(new ServerRequest('GET', '/about'), $handler));
        $this->assertSame('en|en', (string) $visit->getBody());
    }

    public function test_bare_redirect_skips_every_internal_purpose(): void
    {
        $urls = new LocaleUrls(['en', 'de'], 'en', true, true, true);
        $mw = new LocaleMiddleware($this->translator(), ['en', 'de'], 'en', 'prefix', urls: $urls, pageExists: static fn (): bool => true);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Contexts::current()->locale);
            }
        };
        $request = (new ServerRequest('GET', '/faq'))->withCookieParams(['locale' => 'de']);
        $site = self::site($urls);

        $visit = Contexts::run(self::visit('/faq', $site), static fn () => $mw->process($request, $handler));
        $this->assertSame(302, $visit->getStatusCode());
        $this->assertSame('/de/faq', $visit->getHeaderLine('Location'));

        foreach ([Purpose::Audit, Purpose::Geo, Purpose::Rebuild, Purpose::Verify, Purpose::Preview] as $purpose) {
            $ctx = RenderContext::internal($purpose, $site, new SystemClock(), '/faq')->for($request);
            $response = Contexts::run($ctx, static fn () => $mw->process($request, $handler));
            $this->assertSame(200, $response->getStatusCode(), $purpose->value . ': rendered as asked, never redirected');
            $this->assertSame('en', (string) $response->getBody(), $purpose->value);
        }
    }

    public function test_url_prefix_is_derived_from_the_context(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public ?string $prefix = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->prefix = Contexts::current()->request?->localePrefix;

                return new Response(200);
            }
        };
        // Ruling Q1: the prefix is the one the URL carried, not the locale's own; with
        // prefix_default off, /en/about keeps '/en' (its own cache entry, as in 0.80).
        $cases = [
            'prefix_default off' => [new LocaleUrls(['en', 'de'], 'en', true, false), ['/en/about' => '/en', '/de/about' => '/de', '/about' => '', '/de' => '/de']],
            'prefix_default on' => [new LocaleUrls(['en', 'de'], 'en', true, true), ['/en/about' => '/en', '/de/about' => '/de']],
            'no LocaleUrls' => [null, ['/en/about' => '/en', '/de/about' => '/de', '/about' => '']],
        ];
        foreach ($cases as $name => [$urls, $paths]) {
            $mw = new LocaleMiddleware($this->translator(), ['en', 'de'], 'en', 'prefix', urls: $urls);
            foreach ($paths as $path => $expected) {
                $deprecated = null;
                Deprecations::reset();
                $messages = self::deprecations(static function () use ($mw, $handler, $path, $urls, &$deprecated): void {
                    $deprecated = Contexts::run(self::visit($path, self::site($urls)), static function () use ($mw, $handler, $path): string {
                        $mw->process(new ServerRequest('GET', $path), $handler);

                        return $mw->urlPrefix();
                    });
                });
                $this->assertSame($expected, $handler->prefix, "{$name}: {$path} in the context");
                $this->assertSame($expected, $deprecated, "{$name}: {$path} through the deprecated urlPrefix()");
                $this->assertSame(['Preflow\I18n\LocaleMiddleware::urlPrefix() is deprecated since 0.81 and is removed in 0.83; use Contexts::current()->request->localePrefix.'], array_values(array_unique($messages)));
            }
        }

        // The request part keeps the carried prefix when it is pointed at another request of the
        // same visit (the session middleware, the page cache's verify), and a fresh context has none.
        $ctx = self::visit('/de/about')->for(new ServerRequest('GET', '/about'))->prefixed('/de');
        $this->assertSame('/de', $ctx->for(new ServerRequest('GET', '/about'))->request?->localePrefix);
        $this->assertSame('', self::visit('/de/about')->request?->localePrefix);
        $this->assertSame('', RenderContext::internal(Purpose::Audit, self::site(), new SystemClock(), '/de/about')->request?->localePrefix);
        $this->assertSame('/de', unserialize(serialize($ctx))->request->localePrefix);
        $this->assertSame('/de', json_decode((string) json_encode($ctx), true)['request']['localePrefix']);
        Deprecations::reset();
        self::deprecations(fn () => $this->assertSame('', (new LocaleMiddleware($this->translator(), ['en'], 'en'))->urlPrefix(), 'outside every render no URL, no prefix'));
    }

    /** @return array<string, \Preflow\I18n\CatalogueMemo> the translator's text caches, by text locale and overrides switch */
    private static function memos(Translator $t): array
    {
        return (new \ReflectionProperty(Translator::class, 'memos'))->getValue($t);
    }

    public function test_catalogue_cache_is_keyed_per_context(): void
    {
        $t = $this->translator();

        Contexts::run(self::visit(), function () use ($t): void {
            for ($i = 0; $i < 1000; $i++) {
                $this->assertSame('Hello', $t->get('site.hi'));
            }
            $this->assertSame(['en' => 1], $GLOBALS['preflow_locale_ctx_loads'], '1000 calls under one context load the catalogue once');
            $this->assertSame(['en'], array_keys(self::memos($t)), 'one cache for the context\'s locale');
            $this->assertSame(["en\0site.hi" => 'Hello'], self::memos($t)['en']->texts, 'the text resolved once and kept');

            // Served from the cache: a text planted there is what the next call gives.
            self::memos($t)['en']->texts["en\0site.hi"] = 'From the cache';
            $this->assertSame('From the cache', $t->get('site.hi'));
            self::memos($t)['en']->texts["en\0site.hi"] = 'Hello';

            // Another locale has a cache of its own; the first one is kept.
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('de'));
            for ($i = 0; $i < 1000; $i++) {
                $this->assertSame('Hallo', $t->get('site.hi'));
            }
            $this->assertSame(['en', 'de'], array_keys(self::memos($t)));
            $this->assertSame(["de\0site.hi" => 'Hallo'], self::memos($t)['de']->texts);
            $this->assertSame(["en\0site.hi" => 'Hello'], self::memos($t)['en']->texts);

            // A second context with the same locale and overrides switch (a new context per item,
            // within() back and forth) shares the entry.
            self::memos($t)['en']->texts["en\0site.hi"] = 'From the cache';
            Contexts::within(Contexts::current()->in('en'), function () use ($t): void {
                $this->assertSame('From the cache', $t->get('site.hi'));
            });
            self::memos($t)['en']->texts["en\0site.hi"] = 'Hello';
            $this->assertSame('Hallo', $t->get('site.hi'));

            // The overrides switch is part of the key: the admin surface with overrides has its own.
            Contexts::within(Contexts::current()->admin('en'), function () use ($t): void {
                $this->assertSame('Hello', $t->get('site.hi'));
            });
            $this->assertSame(['en', 'de', "en\x01"], array_keys(self::memos($t)));
        });
        Contexts::run(self::visit()->in('de'), function () use ($t): void {
            $this->assertSame('Hallo', $t->get('site.hi'));
        });

        $this->assertSame(['en' => 1, 'de' => 1], $GLOBALS['preflow_locale_ctx_loads'], 'each locale\'s file read once in the process');

        // A change to the translator's layers is seen at once, also under the same context.
        Contexts::run(self::visit(), function () use ($t): void {
            $this->assertSame('Hello', $t->get('site.hi'));
            $t->setOverrides(self::overrides());
            $t->alwaysOverride('site');
            $this->assertSame('Override en', $t->get('site.hi'));
            $this->assertSame([], self::memos($t)['en']->texts, 'an overridable key is never kept');
        });
    }
}
