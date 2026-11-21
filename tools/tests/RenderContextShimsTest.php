<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\AuthManager;
use Preflow\Blocks\BlockLocale;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\BlockRenderer;
use Preflow\Blocks\DefaultBlockLocale;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Bridge\NotifyOutbox;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\NoRenderContext;
use Preflow\Core\Context\ReadMode;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\Surface;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\PageCache\PageCache;
use Preflow\Data\Locales;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\InterfaceLocales;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\Translator;
use Preflow\Media\ConfigLocaleProvider;
use Preflow\Media\ImageTag;
use Preflow\Media\LocaleProvider;
use Preflow\Members\MembersView;
use Preflow\Members\PageLocale;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Publishing\LiveContent;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Validator;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\Geo\RenderScopes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every shim the render-context release (0.81) keeps for old callers, in one table. Each one:
 * - emits one E_USER_DEPRECATED per call site, in the exact format
 *   `{Class}::{method}() is deprecated since 0.81 and is removed in 0.83; use {replacement}.`;
 * - changes, inside a render, only the frame it is called in: what it does is visible through
 *   Contexts::current() (or the frame-bound service it adapts) and gone when that frame closes;
 * - works outside every render, or throws NoRenderContext there and says so with `@throws`;
 * - carries `@deprecated 0.81, removed in 0.83` in its docblock.
 *
 * The outer frame renders in en; the frame the shim is called in starts in de, with the URL
 * prefix /de, so a shim that only reads (urlPrefix(), the locale bridges) shows it reads its own
 * frame. A row's `from` turns both contexts into what the shim needs to change something.
 *
 * Shims that run a callback or adapt an old interface (PageCache::isolate(), RenderScopes::add(),
 * a BlockLocale, LocaleProvider or PageLocale of an app's own) are called through the core code
 * that calls them in a render. Some objects are built without their constructor: only what the
 * shim's path touches is set.
 *
 * Deprecations::reset() runs before each test, so a notice never depends on the order tests run in.
 */
final class RenderContextShimsTest extends TestCase
{
    use ChecksFrameBalance;
    use PublishingScaffold;

    private const string REMOVAL = '@deprecated 0.81, removed in 0.83';

    private const string FORMAT = '/^(?<class>Preflow\\\\[\w\\\\]+)::(?<method>\w+)\(\) is deprecated since 0\.81 and is removed in 0\.83; use .+\.$/';

    /** @var list<string> the E_USER_DEPRECATED messages raised in the test */
    private array $notices = [];

    private string $shimDir = '';

    protected function setUp(): void
    {
        Contexts::reset();
        Deprecations::reset();
        $this->notices = [];
        set_error_handler(function (int $level, string $message): bool {
            $this->notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Contexts::reset();
        Deprecations::reset();
        FolioText::translatorFrom(null);
        if ($this->shimDir !== '') {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->shimDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->shimDir);
        }
        if (isset($this->dir)) {
            $this->removeScaffold();
        }
    }

    /**
     * One row per shim (or per pair that only works together):
     * - messages: the notices it raises, exactly;
     * - doc: the methods (or the interface, method null) that carry the @deprecated tag;
     * - service: builds what the row's calls share (null: nothing);
     * - act: calls the shim inside the frame and returns what it made visible;
     * - look: reads the same thing without the shim (in the frame around it);
     * - expect: what act returns, every time;
     * - from: turns both frames' contexts into what the shim needs (null: as they are);
     * - outside: calls it outside every render and returns what it gave (null: it throws
     *   NoRenderContext there, which `throws` documents); outsideExpect: that value;
     * - outsideMessages: the notices it raises there (default: messages; [] where it is silent
     *   outside a render by design).
     *
     * @return array<string, array{array{messages: list<string>, doc: list<array{class-string, ?string}>, service: ?\Closure, act: \Closure, look: \Closure, expect: mixed, from: ?\Closure, outside: ?\Closure, outsideExpect: mixed, throws?: array{class-string, string}}}>
     */
    public static function shims(): array
    {
        $locale = static fn (): string => Contexts::current()->locale;
        $surface = static fn (): string => Contexts::current()->surface->key();
        $reads = static fn (): string => Contexts::current()->reads->key();
        $purpose = static fn (): string => Contexts::current()->purpose->value;
        $viewer = static fn (): string => Contexts::current()->viewer->primary()?->getAuthId() ?? 'nobody';

        $rows = [
            'Translator::setLocale' => [
                'messages' => ['Preflow\I18n\Translator::setLocale() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->in($locale)).'],
                'doc' => [[Translator::class, 'setLocale']],
                'service' => self::translator(...),
                'act' => static function (Translator $t) use ($locale): string {
                    $t->setLocale('fr');

                    return $locale();
                },
                'look' => $locale,
                'expect' => 'fr',
                'outside' => static function (Translator $t): string {
                    $t->setLocale('fr');

                    return $t->getLocale();
                },
                'outsideExpect' => 'fr',
            ],
            'Translator::useOverrides' => [
                'messages' => ['Preflow\I18n\Translator::useOverrides() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale, $overrides)).'],
                'doc' => [[Translator::class, 'useOverrides']],
                'service' => self::translator(...),
                'act' => static function (Translator $t) use ($surface): string {
                    $t->useOverrides(true);

                    return $surface();
                },
                'look' => $surface,
                'expect' => Surface::admin('de')->key(),
                'outside' => static function (Translator $t): bool {
                    $t->useOverrides(true);

                    return $t->usesOverrides();
                },
                'outsideExpect' => true,
            ],
            'AuthManager::setUser' => [
                'messages' => ['Preflow\Auth\AuthManager::setUser() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->as($viewer)).'],
                'doc' => [[AuthManager::class, 'setUser']],
                'service' => static fn (): AuthManager => new AuthManager([], 'web'),
                'act' => static function (AuthManager $auth) use ($viewer): string {
                    $auth->setUser(new ArrayUser('u1', 'u1@site.test', ''));

                    return $viewer();
                },
                'look' => $viewer,
                'expect' => 'u1',
            ],
            'AuthManager::addUser' => [
                'messages' => ['Preflow\Auth\AuthManager::addUser() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->as($viewer)).'],
                'doc' => [[AuthManager::class, 'addUser']],
                'service' => static fn (): AuthManager => new AuthManager([], 'web'),
                'act' => static function (AuthManager $auth) use ($viewer): string {
                    $auth->addUser(new ArrayUser('u1', 'u1@site.test', ''), 'members');

                    return $viewer();
                },
                'look' => $viewer,
                'expect' => 'u1',
            ],
            'LiveContent::showWorkingCopies' => [
                'messages' => ['Preflow\Publishing\LiveContent::showWorkingCopies() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->workingCopies()).'],
                'doc' => [[LiveContent::class, 'showWorkingCopies']],
                'service' => self::live(...),
                'act' => static function (LiveContent $live) use ($reads): string {
                    $live->showWorkingCopies();

                    return $reads();
                },
                'look' => $reads,
                'expect' => ReadMode::workingCopies()->key(),
                'outside' => static function (LiveContent $live): bool {
                    $live->showWorkingCopies();

                    return $live->showsWorkingCopies();
                },
                'outsideExpect' => true,
            ],
            'LiveContent::isolate' => [
                'messages' => ['Preflow\Publishing\LiveContent::isolate() is deprecated since 0.81 and is removed in 0.83; use a render under RenderContext::published() and memos keyed on RenderContext::memoKey().'],
                'doc' => [[LiveContent::class, 'isolate']],
                'service' => self::live(...),
                'act' => static function (LiveContent $live) use ($reads): string {
                    $live->isolate();

                    return $reads();
                },
                'look' => $reads,
                'expect' => ReadMode::published()->key(),
                'from' => static fn (RenderContext $ctx): RenderContext => $ctx->workingCopies(),
                'outside' => static function (LiveContent $live): array {
                    $restore = $live->isolate();
                    $published = !$live->showsWorkingCopies();
                    $restore();

                    return [$published, $live->showsWorkingCopies()];
                },
                'outsideExpect' => [true, false],
            ],
            'Notifier::discardingQueued (with core)' => [
                'messages' => ['Preflow\Notify\Notifier::discardingQueued() is deprecated since 0.81 and is removed in 0.83; use Application::render() or Contexts::run() with RenderContext::discarding().'],
                'doc' => [[NotifyOutbox::class, 'discarding'], [Notifier::class, 'discardingQueued']],
                'service' => self::notifier(...),
                'act' => static fn (Notifier $n): string => $n->discardingQueued(static fn (): string => Contexts::current()->outbox->name),
                'look' => static fn (): string => Contexts::current()->outbox->name,
                'expect' => 'Discard',
                'outside' => static fn (Notifier $n): string => $n->discardingQueued(static fn (): string => 'ran'),
                'outsideExpect' => 'ran',
            ],
            'CookieQueue::isolated' => [
                'messages' => ['Preflow\Core\Http\CookieQueue::isolated() is deprecated since 0.81 and is removed in 0.83; use Application::render() (a render in its own frame starts with its own cookies).'],
                'doc' => [[CookieQueue::class, 'isolated']],
                'service' => static fn (): CookieQueue => CookieQueue::ofCurrentFrame(),
                // The frame's cookie is set aside while the render runs, and back after it.
                'act' => static function (CookieQueue $q): string {
                    $q->queue('frame=1');
                    $inside = $q->isolated(static function () use ($q): bool {
                        $q->queue('render=1');

                        return $q->has('frame');
                    });
                    $seen = ($inside ? 'frame' : '-') . '|' . ($q->has('render') ? 'render' : '-') . '|' . ($q->has('frame') ? 'frame' : '-');
                    $q->pull();

                    return $seen;
                },
                'look' => static fn (CookieQueue $q): string => '-|-|' . ($q->has('frame') ? 'frame' : '-'),
                'expect' => '-|-|frame',
                'outside' => static function (CookieQueue $q): string {
                    $q->queue('frame=1');
                    $inside = $q->isolated(static fn (): bool => $q->has('frame'));

                    return ($inside ? 'frame' : '-') . '|' . ($q->has('frame') ? 'frame' : '-');
                },
                'outsideExpect' => '-|frame',
            ],
            'PageCache::isolate, isolated' => [
                'messages' => [
                    'Preflow\Core\PageCache\PageCache::isolate() is deprecated since 0.81 and is removed in 0.83; use RenderContext::memoKey() or a context facet.',
                    'Preflow\Core\PageCache\PageCache::isolated() is deprecated since 0.81 and is removed in 0.83; use RenderContext::memoKey() or a context facet.',
                ],
                'doc' => [[PageCache::class, 'isolate'], [PageCache::class, 'isolated']],
                'service' => static fn (): PageCache => new PageCache(),
                // An isolate() callback runs in the frame of the render it wraps; what it narrows stays there.
                'act' => static function (PageCache $cache) use ($locale): string {
                    $cache->isolate(static function (): null {
                        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->in('fr'));

                        return null;
                    });

                    return $cache->isolated($locale);
                },
                'look' => $locale,
                'expect' => 'fr',
                'outside' => static function (PageCache $cache): string {
                    $cache->isolate(static fn (): null => null);

                    return $cache->isolated(static fn (): string => 'ran');
                },
                'outsideExpect' => 'ran',
            ],
            'RenderScopes::add' => [
                'messages' => ['Preflow\View\Geo\RenderScopes::add() is deprecated since 0.81 and is removed in 0.83; use a context facet or RenderContext::memoKey() (each internal render has its own frame and context).'],
                'doc' => [[RenderScopes::class, 'add']],
                'service' => static fn (): RenderScopes => new RenderScopes(),
                // Application::render() runs the scopes around an internal render, inside its frame.
                'act' => static function (RenderScopes $scopes): string {
                    $scopes->add(static function (ServerRequestInterface $request, \Closure $next): ResponseInterface {
                        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->in('fr'));

                        return $next();
                    });

                    return (string) $scopes->run(new ServerRequest('GET', '/'), static fn (): ResponseInterface => new Response(200, [], Contexts::current()->locale))->getBody();
                },
                'look' => $locale,
                'expect' => 'fr',
                'outside' => static function (RenderScopes $scopes): string {
                    $scopes->add(static fn (ServerRequestInterface $request, \Closure $next): ResponseInterface => $next());

                    return (string) $scopes->run(new ServerRequest('GET', '/'), static fn (): ResponseInterface => new Response(200, [], 'ran'))->getBody();
                },
                'outsideExpect' => 'ran',
            ],
            'InternalRender::mark' => [
                'messages' => ['Preflow\Core\Http\InternalRender::mark() is deprecated since 0.81 and is removed in 0.83; use Application::render() with RenderContext::internal().'],
                'doc' => [[InternalRender::class, 'mark']],
                'service' => static fn (self $test): Application => $test->shimApp(),
                // handle() renders a marked request as an internal render, in a frame of its own.
                'act' => static fn (Application $app): string => (string) $app->handle(InternalRender::mark(new ServerRequest('GET', 'https://site.test/shim')))->getBody(),
                'look' => $purpose,
                'expect' => 'audit',
                'outside' => static fn (Application $app): string => (string) $app->handle(InternalRender::mark(new ServerRequest('GET', 'https://site.test/shim')))->getBody(),
                'outsideExpect' => 'audit',
            ],
            'FolioText::use' => [
                'messages' => ['Preflow\Folio\FolioText::use() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale)).'],
                'doc' => [[FolioText::class, 'use']],
                'service' => self::translator(...),
                'act' => static function (Translator $t) use ($surface): string {
                    FolioText::use($t);

                    return $surface();
                },
                'look' => $surface,
                'expect' => Surface::admin('de')->key(),
                'outside' => static fn (Translator $t): bool => FolioText::use($t) instanceof Translator && Contexts::depth() === 0,
                'outsideExpect' => true,
            ],
            'InterfaceLocales::setCurrent' => [
                'messages' => ['Preflow\Folio\Http\InterfaceLocales::setCurrent() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->admin($locale)).'],
                'doc' => [[InterfaceLocales::class, 'setCurrent']],
                'service' => static fn (): InterfaceLocales => new InterfaceLocales(self::translator(), 'en', ['en', 'de', 'fr']),
                'act' => static function (InterfaceLocales $locales) use ($surface): string {
                    $locales->setCurrent('fr');

                    return $surface();
                },
                'look' => $surface,
                'expect' => Surface::admin('fr')->key(),
                // Outside every render there is no interface locale to keep: the default.
                'outside' => static function (InterfaceLocales $locales): string {
                    $locales->setCurrent('fr');

                    return $locales->current();
                },
                'outsideExpect' => 'en',
            ],
            'ContentLocale::set' => [
                'messages' => ['Preflow\Folio\Content\ContentLocale::set() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->in($locale)).'],
                'doc' => [[ContentLocale::class, 'set']],
                'service' => static fn (): ContentLocale => new ContentLocale(self::locales()),
                'act' => static function (ContentLocale $content) use ($locale): string {
                    $content->set('fr');

                    return $locale();
                },
                'look' => $locale,
                'expect' => 'fr',
                // Outside every render there is no locale to keep: the default content locale.
                'outside' => static function (ContentLocale $content): string {
                    $content->set('fr');

                    return $content->get();
                },
                'outsideExpect' => 'en',
            ],
            'ValidatorFactory::useTranslator' => [
                'messages' => ['Preflow\Validation\ValidatorFactory::useTranslator() is deprecated since 0.81 and is removed in 0.83; use the render context\'s surface (Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale))), or pass the translator to the constructor.'],
                'doc' => [[ValidatorFactory::class, 'useTranslator']],
                'service' => static fn (): ValidatorFactory => new ValidatorFactory(new RuleFactory()),
                'act' => static function (ValidatorFactory $factory): string {
                    $factory->useTranslator(self::translator());

                    return self::validatorTranslator($factory);
                },
                'look' => self::validatorTranslator(...),
                'expect' => 'de',
                'outside' => static function (ValidatorFactory $factory): string {
                    $factory->useTranslator(self::translator());

                    return self::validatorTranslator($factory);
                },
                'outsideExpect' => 'en',
            ],
            'LocaleMiddleware::urlPrefix' => [
                'messages' => ['Preflow\I18n\LocaleMiddleware::urlPrefix() is deprecated since 0.81 and is removed in 0.83; use Contexts::current()->request->localePrefix.'],
                'doc' => [[LocaleMiddleware::class, 'urlPrefix']],
                'service' => static fn (): LocaleMiddleware => new LocaleMiddleware(self::translator(), ['en', 'de', 'fr'], 'en'),
                'act' => static fn (LocaleMiddleware $mw): string => $mw->urlPrefix(),
                'look' => static fn (): string => Contexts::current()->request->localePrefix ?? '',
                'expect' => '/de',
                'outside' => static fn (LocaleMiddleware $mw): string => $mw->urlPrefix(),
                'outsideExpect' => '',
            ],
            'BlockLocale::get (an app\'s own BlockLocale)' => [
                'messages' => ['Preflow\Blocks\BlockLocale::get() is deprecated since 0.81 and is removed in 0.83; use ContextBlockLocale::localeOf().'],
                'doc' => [[BlockLocale::class, 'get']],
                'service' => self::blockRenderer(...),
                'act' => static fn (BlockRenderer $blocks): string => (new \ReflectionMethod($blocks, 'locale'))->invoke($blocks),
                'look' => $locale,
                'expect' => 'de',
                'outside' => static fn (BlockRenderer $blocks): string => (new \ReflectionMethod($blocks, 'locale'))->invoke($blocks),
                'outsideExpect' => 'none',
            ],
            'DefaultBlockLocale::get' => [
                'messages' => ['Preflow\Blocks\DefaultBlockLocale::get() is deprecated since 0.81 and is removed in 0.83; use DefaultBlockLocale::localeOf(Contexts::current()).'],
                'doc' => [[DefaultBlockLocale::class, 'get'], [BlockLocale::class, 'get']],
                'service' => static fn (): DefaultBlockLocale => new DefaultBlockLocale(self::locales()),
                'act' => static fn (DefaultBlockLocale $blocks): string => $blocks->get(),
                'look' => $locale,
                'expect' => 'de',
            ],
            'LocaleProvider::current (an app\'s own LocaleProvider)' => [
                'messages' => ['Preflow\Media\LocaleProvider::current() is deprecated since 0.81 and is removed in 0.83; use ContextLocaleProvider::localeOf().'],
                'doc' => [[LocaleProvider::class, 'current']],
                'service' => self::imageTag(...),
                'act' => static fn (ImageTag $tag): string => (new \ReflectionMethod($tag, 'altLocale'))->invoke($tag),
                'look' => $locale,
                'expect' => 'de',
                // Outside every render ImageTag asks current(), as before 0.81, without a notice.
                'outsideMessages' => [],
                'outside' => static fn (ImageTag $tag): string => (new \ReflectionMethod($tag, 'altLocale'))->invoke($tag),
                'outsideExpect' => 'none',
            ],
            'ConfigLocaleProvider::current' => [
                'messages' => ['Preflow\Media\ConfigLocaleProvider::current() is deprecated since 0.81 and is removed in 0.83; use ConfigLocaleProvider::localeOf(Contexts::current()).'],
                'doc' => [[ConfigLocaleProvider::class, 'current'], [LocaleProvider::class, 'current']],
                'service' => static fn (): ConfigLocaleProvider => new ConfigLocaleProvider(static fn (): ?string => self::translator()->getLocale(), 'en'),
                'act' => static fn (ConfigLocaleProvider $provider): string => $provider->current(),
                'look' => $locale,
                'expect' => 'de',
                // Outside every render it is how ImageTag gets the alt-text language: no notice there.
                'outsideMessages' => [],
                'outside' => static fn (ConfigLocaleProvider $provider): string => $provider->current(),
                'outsideExpect' => 'en',
            ],
            'PageLocale::set (an app\'s own PageLocale)' => [
                'messages' => ['Preflow\Members\PageLocale::set() is deprecated since 0.81 and is removed in 0.83; use Contexts::current()->locale.'],
                'doc' => [[PageLocale::class, null]],
                'service' => self::membersView(...),
                // MembersView tells it the page's locale inside the page's render (within()), which ends with the page.
                'act' => static function (MembersView $view): string {
                    try {
                        $view->render('login', [], (new ServerRequest('GET', 'https://site.test/fr/members/login'))->withAttribute('locale', 'fr'));
                    } catch (PageLocaleTold $told) {
                        return $told->getMessage();
                    }

                    return 'not told';
                },
                'look' => $locale,
                'expect' => 'told fr, rendering in fr',
                'throws' => [MembersView::class, 'render'],
                // MembersView needs a render before it tells the PageLocale anything.
                'outsideMessages' => [],
            ],
        ];

        $cases = [];
        foreach ($rows as $name => $row) {
            $cases[$name] = [$row + ['from' => null, 'outside' => null, 'outsideExpect' => null, 'outsideMessages' => $row['messages']]];
        }

        return $cases;
    }

    /** @param array{messages: list<string>, doc: list<array{class-string, ?string}>, service: ?\Closure, act: \Closure, look: \Closure, expect: mixed, from: ?\Closure} $shim */
    #[DataProvider('shims')]
    public function test_a_shim_announces_its_removal_once_and_changes_only_its_own_frame(array $shim): void
    {
        $service = $shim['service'] !== null ? ($shim['service'])($this) : null;
        $from = $shim['from'] ?? static fn (RenderContext $ctx): RenderContext => $ctx;

        [$before, $inside, $after] = Contexts::run($from(self::visit()), function () use ($shim, $service, $from): array {
            $before = ($shim['look'])($service);
            // The frame the shim is called in: de, with the URL prefix /de.
            $inside = Contexts::run($from(self::visit()->in('de')->prefixed('/de')), static function () use ($shim, $service): array {
                $seen = [];
                for ($i = 0; $i < 3; $i++) {
                    $seen[] = ($shim['act'])($service);
                }

                return $seen;
            });

            return [$before, $inside, ($shim['look'])($service)];
        });

        $this->assertSame(array_fill(0, 3, $shim['expect']), $inside, 'what the shim did is visible in its frame, on every call');
        $this->assertNotSame($shim['expect'], $before, 'the frame around it shows something else, so the check means something');
        $this->assertSame($before, $after, 'gone when the frame it was called in closed');
        $this->assertSame(0, Contexts::depth());
        $this->assertEqualsCanonicalizing($shim['messages'], $this->notices, 'one notice per call site, however often it runs');
        foreach ($shim['messages'] as $message) {
            $this->assertMatchesRegularExpression(self::FORMAT, $message);
            preg_match(self::FORMAT, $message, $m);
            $this->assertTrue(method_exists($m['class'], $m['method']), "the message names a real method: {$m['class']}::{$m['method']}()");
        }
    }

    /** @param array{messages: list<string>, doc: list<array{class-string, ?string}>, service: ?\Closure, act: \Closure, outside: ?\Closure, outsideExpect: mixed, throws?: array{class-string, string}} $shim */
    #[DataProvider('shims')]
    public function test_a_shim_outside_every_render_works_or_says_that_it_throws(array $shim): void
    {
        $service = $shim['service'] !== null ? ($shim['service'])($this) : null;

        if ($shim['outside'] === null) {
            [$class, $method] = $shim['throws'] ?? $shim['doc'][0];
            $this->assertStringContainsString('@throws \\Preflow\\Core\\Context\\NoRenderContext', (string) (new \ReflectionMethod($class, (string) $method))->getDocComment(), "{$class}::{$method}() documents that it needs a render");
            try {
                ($shim['act'])($service);
                $this->fail('outside every render it throws NoRenderContext');
            } catch (NoRenderContext) {
            }
        } else {
            $this->assertSame($shim['outsideExpect'], ($shim['outside'])($service));
        }

        $this->assertSame(0, Contexts::depth(), 'it opened no frame');
        $this->assertEqualsCanonicalizing($shim['outsideMessages'], $this->notices, 'outside a render it announces its removal too, once (or stays silent by design)');
    }

    /** @param array{doc: list<array{class-string, ?string}>} $shim */
    #[DataProvider('shims')]
    public function test_a_shim_says_when_it_goes(array $shim): void
    {
        foreach ($shim['doc'] as [$class, $method]) {
            $doc = $method === null ? (new \ReflectionClass($class))->getDocComment() : (new \ReflectionMethod($class, $method))->getDocComment();
            $this->assertStringContainsString(self::REMOVAL, (string) $doc, ($method === null ? $class : "{$class}::{$method}()") . ' carries the removal in its docblock');
        }
    }

    /**
     * Every deprecation the release added is in the table, both ways: each 0.81 message a package
     * raises has a row, each row's message is raised, each `@deprecated 0.81` tag sits on a
     * method (or interface) some row lists in `doc`, and every Deprecations::once() call passes its
     * message as one string literal, so this check can read it. A shim added later without a row,
     * or tagged without a notice, fails here.
     */
    public function test_every_0_81_deprecation_in_the_packages_is_in_the_table(): void
    {
        $messages = [];
        $docs = [];
        foreach (self::shims() as [$shim]) {
            foreach ($shim['messages'] as $message) {
                $messages[$message] = true;
            }
            foreach ($shim['doc'] as [$class, $method]) {
                $docs[$method === null ? $class : "{$class}::{$method}"] = true;
            }
        }
        $found = ['messages' => [], 'tagged' => [], 'opaque' => []];
        foreach (glob(dirname(__DIR__, 2) . '/packages/*/src') ?: [] as $src) {
            $found = array_merge_recursive($found, self::deprecationsIn($src));
        }

        $this->assertNotEmpty($found['messages']);
        $this->assertNotEmpty($found['tagged']);
        $this->assertSame([], $found['opaque'], 'write the message as one string literal');
        $this->assertSame([], array_values(array_diff($found['messages'], array_keys($messages))), 'deprecations without a row in shims()');
        $this->assertSame([], array_values(array_diff(array_keys($messages), $found['messages'])), 'rows whose message no package raises');
        $this->assertSame([], array_values(array_diff($found['tagged'], array_keys($docs))), '@deprecated 0.81 tags on methods no row lists in doc');
        $this->assertSame([], array_values(array_diff(array_keys($docs), $found['tagged'])), 'rows whose doc names a method without the @deprecated 0.81 tag');
    }

    /** The scan behind the table check sees a tag without a notice, a double-quoted message and a message it cannot read. */
    public function test_the_table_check_sees_tag_only_and_double_quoted_shims(): void
    {
        $this->shimDir = sys_get_temp_dir() . '/render_shims_scan_' . bin2hex(random_bytes(4));
        @mkdir($this->shimDir . '/src', 0777, true);
        file_put_contents($this->shimDir . '/src/Old.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Acme\Shims;

            use Preflow\Core\Context\Deprecations;

            /** @deprecated 0.81, removed in 0.83: the whole interface */
            interface OldHook
            {
                public function set(string $locale): void;
            }

            final class Old
            {
                /** @var string a property, never a shim */
                private string $name = Old::class;

                /** @deprecated 0.81, removed in 0.83: tagged, but it never tells anyone */
                public function tagOnly(): void {}

                /** @deprecated 0.81, removed in 0.83: a method named like a keyword */
                public static function use(?string $translator): void {}

                public function quoted(): void
                {
                    Deprecations::once("Acme\\Shims\\Old::quoted() is deprecated since 0.81 and is removed in 0.83; use \"new\".");
                }

                public function built(): void
                {
                    Deprecations::once(sprintf('%s::built() is deprecated since 0.81 and is removed in 0.83; use x.', self::class));
                }

                public function single(): void
                {
                    Deprecations::once('Acme\Shims\Old::single() is deprecated since 0.81 and is removed in 0.83; use \'y\'.', 3);
                }
            }
            PHP);

        $found = self::deprecationsIn($this->shimDir . '/src');

        $this->assertSame(['Acme\Shims\OldHook', 'Acme\Shims\Old::tagOnly', 'Acme\Shims\Old::use'], $found['tagged']);
        $this->assertSame([
            'Acme\Shims\Old::quoted() is deprecated since 0.81 and is removed in 0.83; use "new".',
            'Acme\Shims\Old::single() is deprecated since 0.81 and is removed in 0.83; use \'y\'.',
        ], $found['messages']);
        $this->assertCount(1, $found['opaque']);
        $this->assertStringEndsWith('Old.php:33', $found['opaque'][0]);
    }

    /**
     * What $root's PHP files say about deprecations: the messages Deprecations::once() is given
     * (single- or double-quoted literals), the `Class::method` (or `Class`, for an interface or
     * class) each `@deprecated 0.81` docblock sits on, and every once() call whose message is not
     * one literal (file:line).
     *
     * @return array{messages: list<string>, tagged: list<string>, opaque: list<string>}
     */
    private static function deprecationsIn(string $root): array
    {
        $out = ['messages' => [], 'tagged' => [], 'opaque' => []];
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        foreach ($files as $path) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($path)),
                static fn (array|string $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT], true) || $t[0] === T_DOC_COMMENT,
            ));
            $namespace = '';
            $class = '';
            $tag = false;
            foreach ($tokens as $i => $t) {
                $id = is_array($t) ? $t[0] : $t;
                $next = $tokens[$i + 1] ?? null;
                if ($id === T_NAMESPACE && is_array($next)) {
                    $namespace = $next[1];
                } elseif ($id === T_DOC_COMMENT) {
                    $tag = str_contains($t[1], '@deprecated 0.81');
                } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && is_array($next) && $next[0] === T_STRING
                    && !(is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON)) {
                    $class = $namespace . '\\' . $next[1];
                    if ($tag) {
                        $out['tagged'][] = $class;
                    }
                    $tag = false;
                } elseif ($id === T_FUNCTION && is_array($next)) {
                    // The name's token: T_STRING, or a keyword's (FolioText::use()); a closure has '(' here.
                    if ($tag) {
                        $out['tagged'][] = $class . '::' . $next[1];
                    }
                    $tag = false;
                } elseif ($id === T_VARIABLE || $id === T_CONST) {
                    if ($tag) {
                        $out['tagged'][] = $class . '::' . (is_array($next) && $id === T_CONST ? $next[1] : $t[1]);
                    }
                    $tag = false;
                } elseif ($id === T_STRING && $t[1] === 'once' && is_array($tokens[$i - 2] ?? null) && str_ends_with($tokens[$i - 2][1], 'Deprecations')
                    && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON && $next === '(') {
                    $arg = $tokens[$i + 2] ?? null;
                    $after = $tokens[$i + 3] ?? null;
                    if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING && ($after === ',' || $after === ')')) {
                        $inner = substr($arg[1], 1, -1);
                        $out['messages'][] = $arg[1][0] === "'" ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($inner);
                    } else {
                        $out['opaque'][] = $path . ':' . $t[2];
                    }
                }
            }
        }
        $out['messages'] = array_values(array_unique($out['messages']));

        return $out;
    }

    /** A Folio site boots and answers a visitor and the sign-in page without calling a deprecated shim. */
    #[RunInSeparateProcess]
    public function test_folio_calls_no_deprecated_shim(): void
    {
        $this->scaffoldPublishing();

        $app = $this->app();
        $this->assertSame([], $this->notices, 'booting Folio');
        $app->handle(new ServerRequest('GET', 'https://site.test/'));
        $app->handle(new ServerRequest('GET', 'https://site.test/folio/login'));

        $this->assertSame([], array_values(array_filter($this->notices, static fn (string $m): bool => str_contains($m, 'is deprecated since 0.81'))));
    }

    private static function site(): Site
    {
        return new Site('https://site.test', ['en', 'de', 'fr'], 'en', new LocaleUrls(['en', 'de', 'fr'], 'en'));
    }

    private static function visit(): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), self::site(), new SystemClock());
    }

    private static function locales(): Locales
    {
        return new Locales(['en', 'de', 'fr'], 'en');
    }

    private static function translator(): Translator
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('validation', dirname(__DIR__, 2) . '/packages/validation/lang');

        return $translator;
    }

    /** The language of a validator's messages: its translator's locale, 'en' without one. */
    private static function validatorTranslator(ValidatorFactory $factory): string
    {
        $translator = \Closure::bind(static fn (Validator $v): ?Translator => $v->translator, null, Validator::class)($factory->make([], []));

        return $translator?->getLocale() ?? 'none';
    }

    /** LiveContent's shims touch only the read mode: no store behind it. */
    private static function live(): LiveContent
    {
        return (new \ReflectionClass(LiveContent::class))->newInstanceWithoutConstructor();
    }

    private static function notifier(): Notifier
    {
        $outbox = new NotifyOutbox(static fn (): Notifier => throw new \LogicException('the shim sends nothing'));

        return new Notifier(new TransportFactory([], sys_get_temp_dir()), [], queue: $outbox);
    }

    /** A BlockRenderer with an app's own BlockLocale from before 0.81 (no ContextBlockLocale). */
    private static function blockRenderer(): BlockRenderer
    {
        $locale = new class (self::locales()) implements BlockLocale {
            public function __construct(private readonly Locales $locales) {}

            public function get(): string
            {
                return Contexts::depth() > 0 ? Contexts::current()->locale : 'none';
            }

            public function locales(): Locales
            {
                return $this->locales;
            }
        };
        $components = (new \ReflectionClass(ComponentRenderer::class))->newInstanceWithoutConstructor();

        return new BlockRenderer(new BlockRegistry(), $components, new Container(), $locale);
    }

    /** An ImageTag with an app's own LocaleProvider from before 0.81 (no ContextLocaleProvider). */
    private static function imageTag(): ImageTag
    {
        $provider = new class implements LocaleProvider {
            public function current(): string
            {
                return Contexts::depth() > 0 ? Contexts::current()->locale : 'none';
            }

            public function default(): string
            {
                return 'en';
            }
        };
        $tag = (new \ReflectionClass(ImageTag::class))->newInstanceWithoutConstructor();
        \Closure::bind(static function (ImageTag $tag, LocaleProvider $provider): void {
            $tag->locale = $provider;
        }, null, ImageTag::class)($tag, $provider);

        return $tag;
    }

    /** A MembersView with an app's own PageLocale; the page itself is never rendered (PageLocaleTold stops it). */
    private static function membersView(): MembersView
    {
        $pageLocale = new class implements PageLocale {
            public function set(string $locale): void
            {
                throw new PageLocaleTold("told {$locale}, rendering in " . Contexts::current()->locale);
            }
        };
        $view = (new \ReflectionClass(MembersView::class))->newInstanceWithoutConstructor();
        \Closure::bind(static function (MembersView $view, Locales $locales, PageLocale $pageLocale): void {
            $view->locales = $locales;
            $view->pageLocale = $pageLocale;
        }, null, MembersView::class)($view, self::locales(), $pageLocale);

        return $view;
    }

    /** A site whose one route answers with the purpose of the render it runs in. */
    private function shimApp(): Application
    {
        $this->shimDir = sys_get_temp_dir() . '/render_shims_' . bin2hex(random_bytes(4));
        @mkdir($this->shimDir . '/config', 0777, true);
        file_put_contents($this->shimDir . '/config/app.php', "<?php\nreturn ['name' => 'shims', 'debug' => 0, 'key' => 'render-shims-test-key-0123456789abcdef', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        file_put_contents($this->shimDir . '/config/middleware.php', "<?php\nreturn [" . var_export(ShimPurposeRoute::class, true) . "];\n");
        $app = Application::create($this->shimDir);
        $app->boot();

        return $app;
    }
}

/** Answers /shim with the purpose of the render it runs in. */
final class ShimPurposeRoute implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->getUri()->getPath() === '/shim'
            ? new Response(200, [], Contexts::current()->purpose->value)
            : $handler->handle($request);
    }
}

/** Thrown by the test's PageLocale to stop the member page after it was told its locale. */
final class PageLocaleTold extends \RuntimeException {}
