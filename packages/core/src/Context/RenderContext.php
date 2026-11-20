<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Preflow\Core\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The assumptions a render runs under: why it happens, who is looking, in which locale, with
 * whose texts, reading which version, for which site, at what time, and what happens to the
 * mail and data writes it causes. Immutable: every wither returns a new context and leaves the
 * one it was called on as it was.
 *
 * It may be logged or serialized. What it holds at run time is richer than what leaves it:
 * serialize() and var_dump()/print_r() keep
 * - the request as its method, path and locale prefix only: the PSR request (headers, cookies,
 *   query, body, attributes) is dropped, so a serialized context comes back with `request->request` null;
 * - each identity as its guard and id (Viewer::__serialize()), never the user record, and a
 *   role viewer's RoleView;
 * - SystemClock and FrozenClock as they are; any other clock is serialized as a SystemClock and
 *   dumped as its class name (`now` and whether at() froze it are kept);
 * - each facet that serializes on its own; a facet that cannot (a closure, a resource) is left
 *   out, and its class is listed under `omittedFacets` in the serialized form. A dump shows each
 *   facet as its memoKey().
 * json_encode() gives the dump's shape as plain values (jsonSerialize()): request as path,
 * method and locale prefix, viewer as guard => id, surface, read-mode key, policies by name, clock by class, `now`
 * in ATOM form, facets as class => memoKey() in class order.
 *
 * The PSR request stays reachable on purpose as `$ctx->request->request` (RequestContext's
 * documented use: code without a request reads the visitor's cookie from it). A log line must
 * write the context through one of the three paths above, never by walking its properties.
 * var_export() and an (array) cast are unsafe: both walk the properties and reach the request,
 * cookies included.
 * A render's output (assets, headers, queued mail) lives in its frame, not here.
 *
 * `now` is taken from the clock when the context is built and kept by every wither except at().
 * A synthetic viewer (view as role) never writes or sends: its context skips writes (unless they
 * are refused) and captures what would be sent, whatever wither comes after.
 *
 * @since 0.81.0
 */
final readonly class RenderContext implements \JsonSerializable
{
    /**
     * @param array<class-string<Facet>, Facet> $extensions
     * @param bool $frozen at() froze the clock (a render as of a fixed time; not the public default)
     */
    private function __construct(
        public ?RequestContext $request,
        public Purpose $purpose,
        public Viewer $viewer,
        public string $locale,
        public Surface $surface,
        public ReadMode $reads,
        public Site $site,
        public ClockInterface $clock,
        public \DateTimeImmutable $now,
        public OutboxPolicy $outbox,
        public WritePolicy $writes,
        public array $extensions,
        private bool $frozen,
    ) {}

    /**
     * A visitor's request: anonymous, public, published, in the site's default locale, delivering
     * and writing. A Fragment when the path is a component URL ('/--component/…', also under a
     * locale prefix) or a hypermedia client asked for a fragment (not a history restore), else
     * a Visit.
     */
    public static function fromRequest(ServerRequestInterface $r, Site $site, ClockInterface $clock): self
    {
        $path = $r->getUri()->getPath();
        $component = $site->urls?->strip($path) ?? $path;
        $protocols = $r->getAttribute(HypermediaProtocols::class);
        $fragment = $component === '/--component'
            || str_starts_with($component, '/--component/')
            || ($protocols instanceof HypermediaProtocols && $protocols->wantsFragment($r));

        return self::fresh($fragment ? Purpose::Fragment : Purpose::Visit, $site, $clock)->for($r);
    }

    /** A console command: no request, anonymous, public, published, in the site's default locale. */
    public static function console(Site $site, ClockInterface $clock): self
    {
        return self::fresh(Purpose::Console, $site, $clock);
    }

    /**
     * An internal render of $path (the SEO audit, GEO, a rebuild): an anonymous visitor's clean
     * GET in the site's default locale, published reads, nothing sent, writes skipped.
     *
     * @throws \InvalidArgumentException when $path carries a query or a fragment (a clean GET has neither)
     */
    public static function internal(Purpose $p, Site $site, ClockInterface $clock, string $path): self
    {
        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new \InvalidArgumentException("An internal render is a clean GET of a path without a query or fragment, got '{$path}'.");
        }

        return self::fresh($p, $site, $clock)
            ->asVisitor()
            ->public()
            ->in($site->defaultLocale)
            ->published()
            ->discarding()
            ->skippingWrites()
            ->because($p)
            ->for(new ServerRequest('GET', $path));
    }

    private static function fresh(Purpose $purpose, Site $site, ClockInterface $clock): self
    {
        return new self(
            request: null,
            purpose: $purpose,
            viewer: Viewer::anonymous(),
            locale: $site->defaultLocale,
            surface: Surface::public(),
            reads: ReadMode::published(),
            site: $site,
            clock: $clock,
            now: $clock->now(),
            outbox: OutboxPolicy::Deliver,
            writes: WritePolicy::Allow,
            extensions: [],
            frozen: false,
        );
    }

    /** An anonymous viewer on the public surface. */
    public function asVisitor(): self
    {
        return $this->copy(viewer: Viewer::anonymous(), surface: Surface::public());
    }

    /** $v as the viewer; a synthetic one is asRole(). */
    public function as(Viewer $v): self
    {
        return $this->copy(viewer: $v);
    }

    /** Viewing as $r's roles: a synthetic viewer, writes skipped (unless refused), outbox captured. */
    public function asRole(RoleView $r): self
    {
        return $this->copy(
            viewer: Viewer::synthetic($r),
            writes: $this->writes === WritePolicy::Refuse ? WritePolicy::Refuse : WritePolicy::Skip,
            outbox: OutboxPolicy::Capture,
        );
    }

    /** The content and page locale. */
    public function in(string $locale): self
    {
        return $this->copy(locale: $locale);
    }

    /** The admin's texts in $uiLocale (overrides on unless $overrides is false). */
    public function admin(string $uiLocale, bool $overrides = true): self
    {
        return $this->copy(surface: Surface::admin($uiLocale, $overrides));
    }

    /** The visitor's texts. */
    public function public(): self
    {
        return $this->copy(surface: Surface::public());
    }

    public function published(): self
    {
        return $this->copy(reads: ReadMode::published());
    }

    public function workingCopies(): self
    {
        return $this->copy(reads: ReadMode::workingCopies());
    }

    /** Reads the published state as of $at (reserved: needs revision reads). */
    public function asOf(\DateTimeImmutable $at): self
    {
        return $this->copy(reads: ReadMode::asOf($at));
    }

    /** A frozen clock at $at; now is $at. */
    public function at(\DateTimeImmutable $at): self
    {
        return $this->copy(clock: new FrozenClock($at), now: $at, frozen: true);
    }

    public function delivering(): self
    {
        return $this->copy(outbox: OutboxPolicy::Deliver);
    }

    public function discarding(): self
    {
        return $this->copy(outbox: OutboxPolicy::Discard);
    }

    public function capturing(): self
    {
        return $this->copy(outbox: OutboxPolicy::Capture);
    }

    public function skippingWrites(): self
    {
        return $this->copy(writes: WritePolicy::Skip);
    }

    public function refusingWrites(): self
    {
        return $this->copy(writes: WritePolicy::Refuse);
    }

    /**
     * The request part: path and method from $request, which it carries. The locale prefix the
     * URL carried stays as it was (since 0.81.0: '' for a context without a request part), so a
     * request of the same visit handed on after the locale middleware keeps it; prefixed() sets it.
     */
    public function for(ServerRequestInterface $request): self
    {
        $path = $request->getUri()->getPath();

        return $this->copy(request: new RequestContext($path === '' ? '/' : $path, $request->getMethod(), $request, $this->request->localePrefix ?? ''));
    }

    /**
     * The request part with the locale prefix its URL carried ('/de'; '/en' when the default
     * locale was written into the URL), '' for none. The locale middleware sets it.
     *
     * @since 0.81.0
     *
     * @throws \LogicException without a request part (for() first)
     */
    public function prefixed(string $localePrefix): self
    {
        $request = $this->request ?? throw new \LogicException('RenderContext::prefixed() needs a request part: call for() first.');

        return $this->copy(request: new RequestContext($request->path, $request->method, $request->request, $localePrefix));
    }

    public function because(Purpose $p): self
    {
        return $this->copy(purpose: $p);
    }

    /**
     * A mail to someone reading in $locale: an anonymous visitor, public texts, published reads, no
     * request. Its purpose is Mail, except inside an internal render (Purpose::isInternal(), since
     * 0.81.0), whose purpose it keeps: a mail composed during the SEO audit or a preview leaves no
     * trace where that render leaves none.
     */
    public function forRecipient(string $locale): self
    {
        $mail = $this->asVisitor()->public()->in($locale)->published()->copy(request: null);

        return $this->purpose->isInternal() ? $mail : $mail->because(Purpose::Mail);
    }

    /** $facet added, replacing one of the same class. */
    public function with(Facet $facet): self
    {
        return $this->copy(extensions: [$facet::class => $facet] + $this->extensions);
    }

    /**
     * The facet of class $c, or null.
     *
     * @template T of object
     * @param class-string<T> $c
     * @return T|null
     */
    public function get(string $c): ?object
    {
        $facet = $this->extensions[$c] ?? null;

        return $facet instanceof $c ? $facet : null;
    }

    /**
     * Whether at() froze this context (a render as of a fixed time). A context built on a
     * FrozenClock without at() is not frozen.
     *
     * @since 0.81.0
     */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * What an anonymous visitor gets: anonymous viewer, public surface, published reads, a clock
     * not frozen with at(), and every facet's own isPublicDefault().
     */
    public function isPublicDefault(): bool
    {
        if (!$this->viewer->isAnonymous() || $this->surface->admin || $this->reads->readsWorkingCopies() || $this->reads->at !== null || $this->frozen) {
            return false;
        }
        foreach ($this->extensions as $facet) {
            if (!$facet->isPublicDefault()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The page cache may store this render: its purpose is storable (Visit, Rebuild), it is the
     * public default, and its write and outbox policies are its purpose's own: a visit writes and
     * delivers (Allow, Deliver), a rebuild skips and discards (Skip, Discard; internal()). A
     * render that captures or discards what a visit would send, or skips what it would write (a
     * sub-request inside a capturing render, a preview), is not what a visitor gets.
     */
    public function isStorable(): bool
    {
        if (!$this->purpose->isStorable() || !$this->isPublicDefault()) {
            return false;
        }

        return $this->purpose === Purpose::Rebuild
            ? $this->writes === WritePolicy::Skip && $this->outbox === OutboxPolicy::Discard
            : $this->writes === WritePolicy::Allow && $this->outbox === OutboxPolicy::Deliver;
    }

    /**
     * A stable string of what changes the data a render reads: locale, reads, viewer, surface and
     * each facet as '{class}={memoKey}' in class order, joined by '|'. Equal contexts give equal
     * keys; the purpose, the policies and the request do not count.
     */
    public function memoKey(): string
    {
        $parts = [$this->locale, $this->reads->key(), $this->viewer->key(), $this->surface->key()];
        $extensions = $this->extensions;
        ksort($extensions, SORT_STRING);
        foreach ($extensions as $class => $facet) {
            $parts[] = $class . '=' . $facet->memoKey();
        }

        return implode('|', $parts);
    }

    /** @return array<string, mixed> the context without secrets (see the class docblock) */
    public function __serialize(): array
    {
        $extensions = [];
        $omitted = [];
        foreach ($this->extensions as $class => $facet) {
            try {
                serialize($facet);
                $extensions[$class] = $facet;
            } catch (\Throwable) {
                $omitted[] = $class;
            }
        }

        return [
            'request' => $this->request === null ? null : new RequestContext($this->request->path, $this->request->method, null, $this->request->localePrefix),
            'purpose' => $this->purpose,
            'viewer' => $this->viewer,
            'locale' => $this->locale,
            'surface' => $this->surface,
            'reads' => $this->reads,
            'site' => $this->site,
            'clock' => $this->clock instanceof SystemClock || $this->clock instanceof FrozenClock ? $this->clock : new SystemClock(),
            'now' => $this->now,
            'outbox' => $this->outbox,
            'writes' => $this->writes,
            'extensions' => $extensions,
            'frozen' => $this->frozen,
            'omittedFacets' => $omitted,
        ];
    }

    /** @param array<string, mixed> $data what __serialize() gave */
    public function __unserialize(array $data): void
    {
        $this->request = $data['request'];
        $this->purpose = $data['purpose'];
        $this->viewer = $data['viewer'];
        $this->locale = $data['locale'];
        $this->surface = $data['surface'];
        $this->reads = $data['reads'];
        $this->site = $data['site'];
        $this->clock = $data['clock'];
        $this->now = $data['now'];
        $this->outbox = $data['outbox'];
        $this->writes = $data['writes'];
        $this->extensions = $data['extensions'];
        $this->frozen = $data['frozen'];
    }

    /** @return array<string, mixed> what var_dump and print_r show (see the class docblock) */
    public function __debugInfo(): array
    {
        $data = $this->__serialize();
        unset($data['omittedFacets']);
        $data['clock'] = $this->clock::class;
        $data['extensions'] = array_map(static fn (Facet $facet): string => $facet->memoKey(), $this->extensions);

        return $data;
    }

    /**
     * The context as plain JSON values, without secrets (see the class docblock). A facet key
     * that is not UTF-8 text is given as 'hex:' and its bytes in hex, so json_encode() never fails.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $extensions = array_map(static function (Facet $facet): string {
            $key = $facet->memoKey();

            return preg_match('//u', $key) === 1 ? $key : 'hex:' . bin2hex($key);
        }, $this->extensions);
        ksort($extensions, SORT_STRING);

        return [
            'request' => $this->request === null ? null : ['path' => $this->request->path, 'method' => $this->request->method, 'localePrefix' => $this->request->localePrefix],
            'purpose' => $this->purpose->value,
            'viewer' => $this->viewer->jsonSerialize(),
            'locale' => $this->locale,
            'surface' => ['admin' => $this->surface->admin, 'uiLocale' => $this->surface->uiLocale, 'overrides' => $this->surface->overrides],
            'reads' => $this->reads->key(),
            'site' => ['origin' => $this->site->origin, 'locales' => $this->site->locales, 'defaultLocale' => $this->site->defaultLocale],
            'clock' => $this->clock::class,
            'now' => $this->now->format(\DateTimeInterface::ATOM),
            'frozen' => $this->frozen,
            'outbox' => $this->outbox->name,
            'writes' => $this->writes->name,
            'extensions' => $extensions,
        ];
    }

    /**
     * A copy with the named fields changed (each named argument is a constructor parameter). A
     * synthetic viewer's writes stay skipped (or refused) and its outbox captured (or discarded).
     */
    private function copy(mixed ...$changes): self
    {
        $fields = array_replace(get_object_vars($this), $changes);
        if ($fields['viewer']->isSynthetic()) {
            if ($fields['writes'] === WritePolicy::Allow) {
                $fields['writes'] = WritePolicy::Skip;
            }
            if ($fields['outbox'] === OutboxPolicy::Deliver) {
                $fields['outbox'] = OutboxPolicy::Capture;
            }
        }

        return new self(...$fields);
    }
}
