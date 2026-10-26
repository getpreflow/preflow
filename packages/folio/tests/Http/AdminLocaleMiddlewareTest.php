<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\Authenticatable;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Folio\Http\AdminLocaleMiddleware;
use Preflow\Folio\Http\InterfaceLocales;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\TranslationOverrides;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The admin request runs in the user's interface language with overrides on; afterwards (also
 * after an exception) the translator is back in the frontend's locale with overrides off.
 */
final class AdminLocaleMiddlewareTest extends TestCase
{
    private string $dir;
    private Translator $translator;
    private UserPreferences $prefs;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_admin_locale_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/lang', 0777, true);
        $this->translator = new Translator($this->dir . '/lang', 'it', 'it');
        $this->translator->addDefaultsIfMissing('folio', dirname(__DIR__, 2) . '/lang', 'admin');
        $this->translator->setOverrides(new class implements TranslationOverrides {
            public function get(string $locale, string $key): ?string
            {
                return $key === 'folio.layout.log_out' ? 'Override ' . $locale : null;
            }
        });
        $this->prefs = new UserPreferences(new DataManager(['default' => new JsonFileDriver($this->dir . '/data')]));
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function middleware(string $default = 'en'): AdminLocaleMiddleware
    {
        return new AdminLocaleMiddleware($this->translator, new InterfaceLocales($this->translator, $default), $this->prefs);
    }

    private function request(?Authenticatable $user = null, string $acceptLanguage = ''): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/folio');
        if ($acceptLanguage !== '') {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }

        return $user === null ? $request : $request->withAttribute(Authenticatable::class, $user);
    }

    /** @param \Closure(ServerRequestInterface): void $inside */
    private function handler(\Closure $inside): RequestHandlerInterface
    {
        return new class ($inside) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $inside) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inside)($request);

                return new Response(200);
            }
        };
    }

    public function test_the_users_preference_then_the_default_with_overrides_on_and_restored_after(): void
    {
        $ada = new ArrayUser('ada', 'ada@site.test', '');
        $this->prefs->setLocale('ada', 'fr');
        $seen = [];

        $this->middleware()->process($this->request($ada), $this->handler(function () use (&$seen): void {
            $seen[] = [$this->translator->getLocale(), $this->translator->get('folio.layout.log_out')];
        }));
        $this->middleware('de')->process($this->request(new ArrayUser('bob', 'bob@site.test', '')), $this->handler(function () use (&$seen): void {
            $seen[] = [$this->translator->getLocale(), $this->translator->get('folio.layout.log_out')];
        }));

        $this->assertSame([['fr', 'Override fr'], ['de', 'Override de']], $seen);
        $this->assertSame('it', $this->translator->getLocale(), 'the frontend locale again');
        $this->assertSame('Abmelden', $this->translator->get('folio.layout.log_out', [], 'de'), 'overrides off again');
    }

    public function test_a_handler_that_throws_leaves_nothing_behind(): void
    {
        $ada = new ArrayUser('ada', 'ada@site.test', '');
        $this->prefs->setLocale('ada', 'fr');

        try {
            $this->middleware()->process($this->request($ada), $this->handler(static function (): void {
                throw new \RuntimeException('boom');
            }));
            $this->fail('the exception goes on');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('it', $this->translator->getLocale());
        $this->assertSame('Se déconnecter', $this->translator->get('folio.layout.log_out', [], 'fr'), 'overrides off: the file text');
    }

    public function test_signed_out_pages_follow_the_browser_when_it_names_an_offered_language(): void
    {
        $seen = [];
        $record = function (ServerRequestInterface $request) use (&$seen): void {
            $seen[] = $this->translator->getLocale();
        };

        $this->middleware()->process($this->request(null, 'fr-CH,fr;q=0.9'), $this->handler($record));
        $this->middleware('de')->process($this->request(null, 'it, es;q=0.8'), $this->handler($record));
        $this->middleware()->process($this->request(null), $this->handler($record));

        $this->assertSame(['fr', 'de', 'en'], $seen);
    }

    public function test_a_default_or_preference_that_is_not_offered_falls_back(): void
    {
        $this->prefs->setLocale('ada', 'xx'); // stored before a site dropped its language
        $seen = [];
        $record = function () use (&$seen): void {
            $seen[] = $this->translator->getLocale();
        };

        $this->middleware('it')->process($this->request(new ArrayUser('ada', 'ada@site.test', '')), $this->handler($record));

        $this->assertSame(['en'], $seen, 'no Folio texts in "it": English');
        $this->assertSame(['de', 'en', 'fr'], $this->middleware()->interfaceLocales());
    }

    public function test_the_frontend_state_for_a_preview_inside_an_admin_request(): void
    {
        $ada = new ArrayUser('ada', 'ada@site.test', '');
        $this->prefs->setLocale('ada', 'fr');
        $seen = [];

        $this->middleware()->process($this->request($ada), $this->handler(function (ServerRequestInterface $request) use (&$seen): void {
            $seen[] = AdminLocaleMiddleware::asFrontend($this->translator, $request, fn (): array => [
                $this->translator->getLocale(),
                $this->translator->get('folio.layout.log_out', [], 'fr'),
            ]);
            $seen[] = [$this->translator->getLocale(), $this->translator->get('folio.layout.log_out')];
        }));

        $this->assertSame([['it', 'Se déconnecter'], ['fr', 'Override fr']], $seen);
    }
}
