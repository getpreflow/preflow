<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\CapturedItem;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\OutboxChannel;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderFrame;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\LocaleUrls;

final class FrameOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    public function test_deliver_hands_items_to_the_parent_frame(): void
    {
        $mail = new RecordingChannel('mail');
        $ran = [];

        Contexts::run(self::ctx(), function () use ($mail, &$ran): void {
            Contexts::frame()->outbox()->channel($mail);
            Contexts::frame()->outbox()->queue(new OutboxMail('first'), 'mail');

            Contexts::run(self::ctx()->delivering(), function () use (&$ran): void {
                Contexts::frame()->outbox()->queue(new OutboxMail('second'), 'mail');
                Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                    $ran[] = 'inner work';
                });
            });

            $this->assertSame([], $mail->delivered, 'nothing is sent while a frame is open');
            $this->assertSame([], $ran);
            Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                $ran[] = 'outer work';
            });
        });

        $this->assertSame([['first', 'second']], array_map(
            static fn (array $batch): array => array_map(static fn (OutboxMail $m): string => $m->to, $batch),
            $mail->delivered,
        ), 'the outermost frame delivers through the channel, once, in order');
        $this->assertSame(['inner work', 'outer work'], $ran, 'after-response work moves up and runs when the outermost frame closes');
    }

    public function test_discard_drops_items_and_after_response_work(): void
    {
        $mail = new RecordingChannel('mail');
        $ran = false;

        Contexts::run(self::ctx(), function () use ($mail, &$ran): void {
            Contexts::frame()->outbox()->channel($mail);

            Contexts::run(self::ctx()->discarding(), static function () use (&$ran): void {
                Contexts::frame()->outbox()->queue(new OutboxMail('dropped'), 'mail');
                Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                    $ran = true;
                });
            });

            $this->assertSame([], Contexts::frame()->outbox()->drain(), 'nothing reaches the parent');
        });

        $this->assertSame([], $mail->delivered);
        $this->assertFalse($ran);
    }

    public function test_capture_returns_items_for_the_result(): void
    {
        $mail = new RecordingChannel('mail');
        $ran = false;
        $frame = null;

        $response = Contexts::run(self::ctx(), function () use ($mail, &$ran, &$frame): Response {
            Contexts::frame()->outbox()->channel($mail);

            return Contexts::run(self::ctx()->capturing(), static function () use (&$ran, &$frame): Response {
                $frame = Contexts::frame();
                Contexts::run(self::ctx(), static function (): void {
                    // A delivering frame inside the preview hands its mail to the capturing one.
                    Contexts::frame()->outbox()->queue(new OutboxMail('member@example.test'), 'mail');
                });
                Contexts::frame()->outbox()->queue(new \stdClass(), 'cookies');
                Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                    $ran = true;
                });

                return new Response(200);
            });
        });
        $this->assertInstanceOf(RenderFrame::class, $frame);

        $result = new RenderResult($response, $frame->outbox()->drain(), $frame->skippedWrites());

        $this->assertSame([], $mail->delivered, 'a captured mail is never sent');
        $this->assertFalse($ran, "a captured frame's after-response work is dropped");
        $this->assertSame(1, $result->wouldSend('mail'));
        $this->assertSame(1, $result->wouldSend('cookies'));
        $this->assertSame(0, $result->wouldSend('sms'));
        $this->assertSame(0, $result->wouldWrite());
        $this->assertContainsOnlyInstancesOf(CapturedItem::class, $result->captured);
        $this->assertSame('mail', $result->captured[0]->channel);
        $this->assertSame('Mail to member@example.test', $result->captured[0]->summary, 'the summary comes from the channel');
        $this->assertSame(\stdClass::class, $result->captured[1]->summary, 'an item without a channel is named by its class');
        $this->assertSame(200, $result->response->getStatusCode());
    }

    public function test_skipped_writes_reach_the_opening_run(): void
    {
        $frame = null;

        Contexts::run(self::ctx()->skippingWrites(), static function () use (&$frame): void {
            $frame = Contexts::frame();
            $frame->recordSkippedWrite(new SkippedWrite('default', 'post', '1', 'save'));
            Contexts::run(self::ctx()->discarding()->skippingWrites(), static function (): void {
                Contexts::frame()->recordSkippedWrite(new SkippedWrite('default', 'counter', 'home', 'increment'));
            });
            try {
                Contexts::run(self::ctx()->capturing()->skippingWrites(), static function (): void {
                    Contexts::frame()->recordSkippedWrite(new SkippedWrite('logs', 'hit', null, 'log'));
                    throw new \RuntimeException('render failed');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertInstanceOf(RenderFrame::class, $frame);
        $result = new RenderResult(new Response(200), [], $frame->skippedWrites());
        $this->assertSame(3, $result->wouldWrite(), 'whatever the nested policy, and also when a nested render throws');
        $this->assertSame(
            ['save post 1', 'increment counter home', 'log hit -'],
            array_map(static fn (SkippedWrite $w): string => "{$w->operation} {$w->type} " . ($w->id ?? '-'), $result->skippedWrites),
        );
    }

    public function test_a_skipped_write_names_a_known_operation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SkippedWrite('default', 'post', '1', 'upsert');
    }

    public function test_an_item_for_a_channel_nobody_registered_is_not_lost_silently(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'sms'");
        Contexts::run(self::ctx(), static function (): void {
            Contexts::frame()->outbox()->queue(new \stdClass(), 'sms');
        });
    }

    public function test_a_channel_registered_on_a_frame_is_known_to_the_frames_inside_it(): void
    {
        $mail = new RecordingChannel('mail');

        $frame = null;
        Contexts::run(self::ctx(), static function () use ($mail, &$frame): void {
            Contexts::frame()->outbox()->channel($mail);
            Contexts::run(self::ctx()->capturing(), static function () use (&$frame): void {
                $frame = Contexts::frame();
                Contexts::frame()->outbox()->queue(new OutboxMail('a@example.test'), 'mail');
            });
        });

        $this->assertSame('Mail to a@example.test', $frame?->outbox()->drain()[0]->summary);
    }

    /** Capture is a preview's promise: nothing inside a capturing or discarding frame sends. */
    public function test_only_the_outermost_delivering_frame_sends(): void
    {
        $mail = new RecordingChannel('mail');
        $ran = false;
        $refused = [];
        $frame = null;

        Contexts::run(self::ctx(), function () use ($mail, &$ran, &$refused, &$frame): void {
            Contexts::frame()->outbox()->channel($mail);
            foreach ([self::ctx()->capturing(), self::ctx()->discarding(), self::ctx()] as $ctx) {
                Contexts::run($ctx, static function () use (&$ran, &$refused, &$frame): void {
                    $frame = Contexts::frame();
                    $outbox = Contexts::frame()->outbox();
                    $outbox->queue(new OutboxMail('member@example.test'), 'mail');
                    $outbox->afterResponse(static function () use (&$ran): void {
                        $ran = true;
                    });
                    foreach ([$outbox->deliver(...), $outbox->runAfterResponse(...), Contexts::deliverNow(...)] as $send) {
                        try {
                            $send();
                        } catch (\LogicException) {
                            $refused[] = Contexts::current()->outbox->name;
                        }
                    }
                    $outbox->discard(); // keep the outer frame clean for the next case
                });
            }
            $this->assertSame([], $mail->delivered, 'nothing was sent from inside a nested frame');
            $this->assertFalse($ran, 'no after-response work ran from inside a nested frame');
        });

        $this->assertSame(['Capture', 'Capture', 'Capture', 'Discard', 'Discard', 'Discard', 'Deliver', 'Deliver', 'Deliver'], $refused, 'every attempt is refused loudly');

        // A held frame that has closed cannot send either.
        $this->expectException(\LogicException::class);
        $frame?->outbox()->deliver();
    }

    public function test_the_outermost_frame_can_deliver_early_with_deliver_now(): void
    {
        $mail = new RecordingChannel('mail');

        Contexts::run(self::ctx(), function () use ($mail): void {
            Contexts::frame()->outbox()->channel($mail);
            Contexts::frame()->outbox()->queue(new OutboxMail('a@example.test'), 'mail');
            Contexts::deliverNow();
            $this->assertCount(1, $mail->delivered, 'sent at once (after the response went out)');
        });
        $this->assertCount(1, $mail->delivered, 'and not again when the frame closes');

        $this->expectException(\LogicException::class);
        Contexts::run(self::ctx()->discarding(), static fn () => Contexts::deliverNow());
    }

    public function test_after_response_work_that_queues_more_is_delivered_too(): void
    {
        $mail = new RecordingChannel('mail');
        $ran = [];

        Contexts::run(self::ctx(), static function () use ($mail, &$ran): void {
            Contexts::frame()->outbox()->channel($mail);
            Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                $ran[] = 'rebuild';
                Contexts::frame()->outbox()->queue(new OutboxMail('late@example.test'), 'mail');
                Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                    $ran[] = 'follow-up';
                    Contexts::frame()->outbox()->queue(new OutboxMail('later@example.test'), 'mail');
                });
            });
        });

        $this->assertSame(['rebuild', 'follow-up'], $ran);
        $this->assertSame(['late@example.test', 'later@example.test'], array_map(
            static fn (OutboxMail $m): string => $m->to,
            array_merge(...$mail->delivered),
        ), 'mail queued by after-response work is sent, not dropped');
    }

    public function test_work_that_keeps_queueing_stops_after_ten_rounds_and_is_logged(): void
    {
        $mail = new RecordingChannel('mail');
        $rounds = 0;
        $log = tempnam(sys_get_temp_dir(), 'outbox-log');
        $previous = ini_set('error_log', (string) $log);

        try {
            Contexts::run(self::ctx(), static function () use ($mail, &$rounds): void {
                Contexts::frame()->outbox()->channel($mail);
                $again = null;
                $again = static function () use (&$again, &$rounds): void {
                    $rounds++;
                    Contexts::frame()->outbox()->queue(new OutboxMail('loop@example.test'), 'mail');
                    Contexts::frame()->outbox()->afterResponse($again);
                };
                Contexts::frame()->outbox()->afterResponse($again);
            });
            $logged = (string) file_get_contents((string) $log);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink((string) $log);
        }

        $this->assertSame(10, $rounds, 'ten rounds, then it stops');
        $this->assertCount(9, $mail->delivered, 'the first round had nothing to send yet');
        $this->assertStringContainsString('1 queued item and 1 piece of after-response work', $logged, 'what is left is logged, never dropped silently');
        $this->assertSame(0, Contexts::depth());
    }

    private static function ctx(): RenderContext
    {
        $site = new Site('https://example.test', ['en'], 'en', new LocaleUrls(['en'], 'en'));

        return RenderContext::fromRequest(new ServerRequest('GET', '/'), $site, new SystemClock());
    }
}

final readonly class OutboxMail
{
    public function __construct(public string $to) {}
}

final class RecordingChannel implements OutboxChannel
{
    /** @var list<list<object>> */
    public array $delivered = [];

    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function deliver(array $items): void
    {
        $this->delivered[] = $items;
    }

    public function describe(object $item): string
    {
        return $item instanceof OutboxMail ? 'Mail to ' . $item->to : $item::class;
    }
}
