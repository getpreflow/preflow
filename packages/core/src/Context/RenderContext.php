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
 * It holds plain values only (no closure, no service, no secret), so it may be logged or
 * serialized. A render's output (assets, headers, queued mail) lives in its frame, not here.
 *
 * `now` is taken from the clock when the context is built and kept by every wither except at().
 * A synthetic viewer (view as role) never writes or sends: its context skips writes (unless they
 * are refused) and captures what would be sent, whatever wither comes after.
 *
 * @since 0.81.0
 */
final readonly class RenderContext
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
     */
    public static function internal(Purpose $p, Site $site, ClockInterface $clock, string $path): self
    {
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

    /** The request part: path and method from $request, which it carries. */
    public function for(ServerRequestInterface $request): self
    {
        $path = $request->getUri()->getPath();

        return $this->copy(request: new RequestContext($path === '' ? '/' : $path, $request->getMethod(), $request));
    }

    public function because(Purpose $p): self
    {
        return $this->copy(purpose: $p);
    }

    /** A mail to someone reading in $locale: an anonymous visitor, public texts, published reads, no request. */
    public function forRecipient(string $locale): self
    {
        return $this->asVisitor()->public()->in($locale)->published()->because(Purpose::Mail)->copy(request: null);
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

    /** The page cache may store this render: its purpose is storable (Visit, Rebuild) and it is the public default. */
    public function isStorable(): bool
    {
        return $this->purpose->isStorable() && $this->isPublicDefault();
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
