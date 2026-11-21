<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Context\WritesRefused;
use Preflow\Publishing\Console\PublishScheduledCommand;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\SchemaPublishingTypes;
use Preflow\Publishing\Tests\Fixtures\ReadRoute;
use Preflow\Publishing\Tests\Fixtures\Recorder;
use Preflow\Publishing\LiveContent;
use Preflow\Publishing\PreviewLinks;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\Tests\PublishingAppTestCase;
use Preflow\Publishing\UnsupportedReadMode;

/**
 * Since 0.81 publishing reads the render context: LiveContent the read mode of the current
 * frame (published outside every render, ruling R1), publish:scheduled its console context's
 * now, and the clock seams the context's clock. showWorkingCopies() and isolate() are deprecated
 * shims bound to the frame they were called in. Publisher and RecordDeleter write through
 * DataManager, so a render that skips or refuses writes never publishes or deletes.
 */
final class ReadModeTest extends PublishingAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Contexts::reset();
        Deprecations::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        Deprecations::reset();
        parent::tearDown();
    }

    private function visit(): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', '/'), $this->app()->site(), new SystemClock());
    }

    private function live(): LiveContent
    {
        return $this->app()->container()->get(LiveContent::class);
    }

    /** "Published" as its snapshot, "Draft" as its working copy. */
    private function seedPost(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Published']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Draft']]);
    }

    private function title(): ?string
    {
        return $this->live()->find('post', 'p1')?->get('title')['en'] ?? null;
    }

    /** @return list<string> the titles query() lists */
    private function listed(): array
    {
        return array_map(static fn ($r): string => $r->get('title')['en'], $this->live()->query('post')->get()->items());
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

    public function test_live_content_follows_the_frame_read_mode(): void
    {
        $this->seedPost();
        $this->working('note', 'n1', ['title' => ['en' => 'Note']]);

        $this->assertSame('Published', $this->title(), 'outside every render: published (ruling R1)');

        Contexts::run($this->visit(), function (): void {
            $this->assertSame('Published', $this->title());
            $this->assertSame(['Published'], $this->listed());
            $this->assertFalse($this->live()->showsWorkingCopies());
            $this->assertTrue($this->live()->readsWorkingCopies('note'), 'a type without published copies reads its working copies');

            Contexts::within(Contexts::current()->workingCopies(), function (): void {
                $this->assertSame('Draft', $this->title());
                $this->assertSame(['Draft'], $this->listed());
                $this->assertTrue($this->live()->showsWorkingCopies());
            });
            $this->assertSame('Published', $this->title(), 'the stretch ended');

            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->workingCopies());
            $this->assertSame('Draft', $this->title());

            // A render inside it (a rebuild behind an admin request) reads what its own context says.
            Contexts::run($this->visit()->published(), function (): void {
                $this->assertSame('Published', $this->title());
            });
            $this->assertSame('Draft', $this->title(), 'the outer frame is back');
        });

        Contexts::run($this->visit(), function (): void {
            $this->assertSame('Published', $this->title(), 'the next render starts published');
        });
        $this->assertSame('Published', $this->title());
    }

    public function test_show_working_copies_is_a_deprecated_shim_bound_to_its_frame(): void
    {
        $this->seedPost();

        $messages = self::deprecations(function (): void {
            Contexts::run($this->visit(), function (): void {
                for ($i = 0; $i < 3; $i++) {
                    $this->live()->showWorkingCopies();
                }
                $this->assertTrue(Contexts::current()->reads->readsWorkingCopies(), 'the shim narrows the frame');
                $this->assertGreaterThan(0, Contexts::frame()->departures(), 'the page cache sees the frame left the public default');
                $this->assertSame('Draft', $this->title());

                $this->live()->showWorkingCopies(false);
                $this->assertSame('Published', $this->title());
                $this->live()->showWorkingCopies();
            });
        });

        // Once per call site: the loop warns once, the two calls after it once each.
        $this->assertSame(array_fill(0, 3, 'Preflow\Publishing\LiveContent::showWorkingCopies() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->workingCopies()).'), $messages);
        $this->assertSame('Published', $this->title(), 'the frame closed: published again');
        Contexts::run($this->visit(), function (): void {
            $this->assertSame('Published', $this->title(), 'the next render never sees it');
        });

        // Outside every render the shim sets what reads outside renders see; a render never sees it.
        self::deprecations(fn () => $this->live()->showWorkingCopies());
        $this->assertSame('Draft', $this->title());
        Contexts::run($this->visit(), function (): void {
            $this->assertSame('Published', $this->title());
        });
    }

    public function test_as_of_is_refused_clearly(): void
    {
        $this->seedPost();
        $date = new \DateTimeImmutable('2026-01-01T00:00:00Z');

        foreach (['find' => fn () => $this->title(), 'query' => fn () => $this->live()->query('post'), 'readsWorkingCopies' => fn () => $this->live()->readsWorkingCopies('post')] as $read => $fn) {
            Contexts::run($this->visit()->asOf($date), function () use ($read, $fn): void {
                try {
                    $fn();
                    $this->fail("{$read}() under asOf() must throw");
                } catch (UnsupportedReadMode $e) {
                    $this->assertSame('Reading the site as of a date is not supported yet.', $e->getMessage());
                }
            });
        }
    }

    public function test_scheduler_uses_the_context_now(): void
    {
        $this->working('post', 'due', ['title' => ['en' => 'Due'], '_publish_at' => '2026-02-28T12:00']);
        $this->working('post', 'later', ['title' => ['en' => 'Later'], '_publish_at' => '2026-03-02T00:00']);
        $console = RenderContext::console($this->app()->site(), new SystemClock())->at(new \DateTimeImmutable('2026-03-01T00:00:00+00:00'));

        ob_start();
        $code = Contexts::run($console, fn (): int => (new PublishScheduledCommand(fn () => $this->app()))->execute([]));
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code, $out);
        $this->assertSame("Published post/due\n", $out);
        $snapshot = $this->publisher()->snapshot('post', 'due');
        $this->assertNotNull($snapshot);
        $this->assertSame('2026-03-01T00:00:00+00:00', $snapshot->get('_published_at'), 'the publisher stamps with the context clock');
        $this->assertNull($this->publisher()->snapshot('post', 'later'));
        $this->assertSame('2026-03-02T00:00', $this->stored('post', 'later')->get('_publish_at'));

        // Outside every render the clock seams fall back to the system clock.
        $links = $this->app()->container()->get(PreviewLinks::class);
        $this->working('post', 'p2', ['title' => ['en' => 'Two'], '_share_token' => 'n2']);
        $this->assertNotNull($links->verify($links->create('post', 'p2', 'n2')));
        // A link made in a render frozen far in the past has expired by now.
        $old = Contexts::run($console->at(new \DateTimeImmutable('2000-01-01T00:00:00Z')), fn (): string => $links->create('post', 'p2', 'n2'));
        $this->assertNull($links->verify($old));
    }

    public function test_scheduler_reads_publish_at_in_the_app_timezone(): void
    {
        // The console builds its context before the app boots and sets app.timezone: "Publish at"
        // is still read in the app's zone, as before 0.81.
        $zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $console = RenderContext::console($this->app()->site(), new SystemClock())->at(new \DateTimeImmutable('2026-03-01T00:00:00Z'));
            $this->write('config/app.php', "<?php\nreturn ['name' => 'Publishing', 'debug' => 1, 'key' => 'publishing-app-test-key-0123456789abcdef', 'timezone' => 'Pacific/Auckland'];\n");
            $this->reboot();
            // 13:00 in Auckland (UTC+13): 12:00 there is due, 14:00 is not.
            $this->working('post', 'due', ['title' => ['en' => 'Due'], '_publish_at' => '2026-03-01T12:00']);
            $this->working('post', 'later', ['title' => ['en' => 'Later'], '_publish_at' => '2026-03-01T14:00']);

            ob_start();
            Contexts::run($console, fn (): int => (new PublishScheduledCommand(fn () => $this->app()))->execute([]));
            $out = (string) ob_get_clean();

            $this->assertSame("Published post/due\n", $out);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_live_content_isolate_is_a_deprecated_adapter(): void
    {
        $this->seedPost();

        $messages = self::deprecations(function (): void {
            Contexts::run($this->visit()->workingCopies(), function (): void {
                $restore = $this->live()->isolate();
                $this->assertSame('Published', $this->title(), 'isolate() switches the frame to published reads');
                $this->assertFalse($this->live()->showsWorkingCopies());
                $restore();
                $this->assertSame('Draft', $this->title(), 'and its closure puts the working copies back');

                // A closure from another frame does nothing here.
                $inner = null;
                Contexts::run($this->visit()->workingCopies(), function () use (&$inner): void {
                    $inner = $this->live()->isolate();
                });
                Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->published());
                $inner();
                $this->assertSame('Published', $this->title(), 'a closure only acts in its own frame');
            });

            // Already published: nothing to switch or put back.
            Contexts::run($this->visit(), function (): void {
                $restore = $this->live()->isolate();
                $this->assertSame(0, Contexts::frame()->departures());
                $restore();
                $this->assertSame('Published', $this->title());
                $this->assertSame(0, Contexts::frame()->departures());
            });

            // Outside every render: the default for reads outside renders, put back afterwards.
            $this->live()->showWorkingCopies();
            $restore = $this->live()->isolate();
            $this->assertSame('Published', $this->title());
            $restore();
            $this->assertSame('Draft', $this->title());
        });

        $this->assertContains('Preflow\Publishing\LiveContent::isolate() is deprecated since 0.81 and is removed in 0.83; use a render under RenderContext::published() and memos keyed on RenderContext::memoKey().', $messages);
    }

    public function test_a_render_that_skips_or_refuses_writes_never_publishes_or_deletes(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Draft']]);
        $this->publisher()->publish($this->working('post', 'p2', ['title' => ['en' => 'Two']]), null);
        $deleter = $this->app()->container()->get(RecordDeleter::class);

        // A preview or a view as a role: skipped and reported, as if written.
        $skipped = Contexts::run($this->visit()->workingCopies()->skippingWrites(), function () use ($deleter): array {
            $this->publisher()->publish($this->stored('post', 'p1'), null);
            $deleter->delete('post', ['p2']);

            return Contexts::frame()->skippedWrites();
        });
        $this->assertNotEmpty($skipped);
        $this->assertNull($this->publisher()->snapshot('post', 'p1'), 'nothing was published');
        $this->assertNotNull($this->dm()->findType('post', 'p2'), 'nothing was deleted');
        $this->assertNotNull($this->publisher()->snapshot('post', 'p2'));

        // A fragment under a preview token: refused.
        foreach ([fn () => $this->publisher()->publish($this->stored('post', 'p1'), null), fn () => $deleter->delete('post', ['p2'])] as $write) {
            try {
                Contexts::run($this->visit()->workingCopies()->refusingWrites(), $write);
                $this->fail('a refusing render must not write');
            } catch (WritesRefused) {
            }
        }
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
        $this->assertNotNull($this->dm()->findType('post', 'p2'));
    }

    /**
     * Under Skip nothing that follows a write runs: no index, addresses or page-cache news, no
     * activity, no after-hooks, no type's own save (it is reported as skipped instead). Under
     * Allow every step runs as before.
     */
    public function test_nothing_after_a_skipped_write_runs(): void
    {
        $this->publisher()->publish($this->working('post', 'gone', ['title' => ['en' => 'Gone']]), null);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'One']]), null);
        $recorder = new Recorder($this->dm());
        $recorder->replaces = ['note'];
        $publisher = new Publisher($this->dm(), new SchemaPublishingTypes($this->dm()), $recorder, index: $recorder, addresses: $recorder);
        $deleter = new RecordDeleter($this->dm(), $publisher, $recorder, $recorder, $recorder);
        $writer = new RecordWriter($this->dm(), $publisher, $recorder, $recorder, $recorder, $recorder, $recorder);
        $steps = function () use ($publisher, $deleter, $writer): void {
            $publisher->publish($this->stored('post', 'p1'), null);
            $writer->save($this->stored('post', 'p1'));
            $writer->save(\Preflow\Data\DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('note'), ['uuid' => 'n1', 'title' => ['en' => 'Note']]));
            $publisher->unpublish('post', 'p1');
            $deleter->delete('post', ['gone']);
        };
        $after = static fn (array $log): array => array_values(array_filter($log, static fn (string $line): bool => preg_match('/^(after|afterDelete|refresh|forget|sync|removed|changed|save):/', $line) === 1));

        $skipped = Contexts::run($this->visit()->workingCopies()->skippingWrites(), static function () use ($steps): array {
            $steps();

            return Contexts::frame()->skippedWrites();
        });

        $this->assertSame([], $after($recorder->log), 'nothing after a skipped write');
        $this->assertContains('note', array_map(static fn ($w): string => $w->type, $skipped), 'the type\'s own save is reported as skipped');
        $this->assertNotNull($this->publisher()->snapshot('post', 'p1'));
        $this->assertNotNull($this->dm()->findType('post', 'gone'));

        Contexts::run($this->visit(), $steps);
        $ran = $after($recorder->log);
        foreach (['refresh:post:p1', 'after:post:p1', 'save:note:n1', 'after:note:n1', 'removed:post:p1', 'forget:post:gone', 'removed:post:gone', 'afterDelete:post:gone'] as $step) {
            $this->assertContains($step, $ran, "under Allow: {$step}");
        }
    }

    /** @return iterable<string, array{\Closure(RenderContext): RenderContext, string}> */
    public static function outerReadModes(): iterable
    {
        yield 'working copies' => [static fn (RenderContext $c): RenderContext => $c->workingCopies(), 'Draft'];
        yield 'published' => [static fn (RenderContext $c): RenderContext => $c->published(), 'Published'];
        yield 'as of a date' => [static fn (RenderContext $c): RenderContext => $c->asOf(new \DateTimeImmutable('2020-01-01T00:00:00Z')), UnsupportedReadMode::class];
    }

    /** A request the app handles inside a render reads what that render reads. */
    #[\PHPUnit\Framework\Attributes\DataProvider('outerReadModes')]
    public function test_a_nested_request_reads_what_its_frame_reads(\Closure $reads, string $expected): void
    {
        $this->write('config/middleware.php', "<?php\nreturn [" . var_export(ReadRoute::class, true) . "];\n");
        $this->seedPost();
        ReadRoute::$seen = [];

        $this->app()->render($reads($this->visit()), new ServerRequest('GET', '/outer'));

        $this->assertSame([$expected], ReadRoute::$seen);
    }
}
