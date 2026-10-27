<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Preflow\Codes\CodeEvents;
use Preflow\Codes\CodeRecord;
use Preflow\Codes\Codes;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;

final class CodeEventsTest extends CodesAppTestCase
{
    private CodeEvents $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new CodeEvents();
    }

    private function withEvents(): Codes
    {
        $c = $this->app()->container();

        return new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            require $this->dir . '/config/codes.php',
            fn (): \DateTimeImmutable => $this->now,
            $this->dir . '/storage/locks',
            $this->events,
        );
    }

    public function test_redeemed_listener_gets_record_redeemer_and_payload(): void
    {
        $codes = $this->withEvents();
        $got = [];
        $this->events->on(CodeEvents::REDEEMED, function (array $p) use (&$got): void {
            $got[] = $p;
        });
        $c = $codes->issue('q', ['group' => 'g1']);
        $codes->redeem($c, 'q', 'm1');

        $this->assertCount(1, $got);
        $this->assertSame(['code', 'purpose', 'redeemer', 'payload'], array_keys($got[0]));
        $this->assertInstanceOf(CodeRecord::class, $got[0]['code']);
        $this->assertSame(1, $got[0]['code']->uses);
        $this->assertSame('q', $got[0]['purpose']);
        $this->assertSame('m1', $got[0]['redeemer']);
        $this->assertSame(['group' => 'g1'], $got[0]['payload']);
    }

    public function test_listener_limited_to_purpose(): void
    {
        $codes = $this->withEvents();
        $order = [];
        $this->events->on(CodeEvents::REDEEMED, function () use (&$order): void { $order[] = 'a'; }, 'p');
        $this->events->on(CodeEvents::REDEEMED, function () use (&$order): void { $order[] = 'all'; });
        $this->events->on(CodeEvents::REDEEMED, function () use (&$order): void { $order[] = 'q'; }, 'q');

        $codes->redeem($codes->issue('q', []), 'q');
        $this->assertSame(['all', 'q'], $order);
        $order = [];
        $codes->redeem($codes->issue('p', [], 'BOOK1234'), 'p');
        $this->assertSame(['a', 'all'], $order);
    }

    public function test_throwing_listener_undoes_use_and_redemption(): void
    {
        $this->assertUndo($this->withEvents(...));
    }

    public function test_throwing_listener_undo_on_json_driver(): void
    {
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'drivers' => [
                'sqlite' => ['path' => $this->dir . '/storage/app.sqlite'],
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
            ],
            'aliases' => ['codes' => 'json'],
        ], true) . ";\n");
        $this->assertUndo($this->withEvents(...));
    }

    private function assertUndo(\Closure $make): void
    {
        $codes = $make();
        $calls = 0;
        $this->events->on(CodeEvents::REDEEMED, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('group');
            }
        });
        $c = $codes->issue('q', [], maxUses: 1);
        $id = (string) $codes->check($c, 'q')->codeId;

        try {
            $codes->redeem($c, 'q', 'm1', 'client-1');
            $this->fail('the listener exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('group', $e->getMessage());
        }
        $this->assertSame(0, $codes->get($id)->uses);
        $this->assertSame([], $codes->redemptions($id));
        $this->assertFalse($this->app()->container()->get(Throttle::class)->tooMany('codes.q', 'client-1', 1, 900));

        $this->assertTrue($codes->redeem($c, 'q', 'm1')->ok);
        $this->assertSame(2, $calls);
        $this->assertSame(1, $codes->get($id)->uses);
        $this->assertCount(1, $codes->redemptions($id));
    }

    public function test_failing_redemption_insert_gives_the_use_back_and_rethrows(): void
    {
        $codes = $this->withEvents();
        $c = $codes->issue('q', [], maxUses: 1);
        $id = (string) $codes->check($c, 'q')->codeId;
        $this->app()->container()->get(DataManager::class)->execute('DROP TABLE code_redemption', [], 'default');

        try {
            $codes->redeem($c, 'q', 'm1');
            $this->fail('the insert failure should propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, $codes->get($id)->uses);
    }

    public function test_undo_failure_keeps_the_listeners_exception(): void
    {
        $codes = $this->withEvents();
        $c = $codes->issue('q', [], maxUses: 1);
        $id = (string) $codes->check($c, 'q')->codeId;
        $dm = $this->app()->container()->get(DataManager::class);
        $log = ini_set('error_log', $this->dir . '/storage/php.log'); // the undo failure is logged
        $this->events->on(CodeEvents::REDEEMED, static function () use ($dm): void {
            $dm->execute('DROP TABLE code_redemption', [], 'default'); // the undo's delete will fail
            throw new \RuntimeException('group');
        });

        try {
            $codes->redeem($c, 'q', 'm1');
            $this->fail('the listener exception should propagate');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertSame('group', $e->getMessage());
        }
        ini_set('error_log', (string) $log);
        $this->assertSame(0, $codes->get($id)->uses);
        $this->assertStringContainsString('undoing a failed redeem failed', (string) file_get_contents($this->dir . '/storage/php.log'));
    }

    public function test_dispatch_without_events_reads_nothing_extra(): void
    {
        $codes = $this->codes(); // no CodeEvents
        $c = $codes->issue('q', []);
        $id = (string) $codes->check($c, 'q')->codeId;
        $codes->revoke($id);
        $this->assertTrue($codes->redeem($codes->issue('q', []), 'q')->ok);
        $this->assertNotNull($codes->get($id)->revokedAt);
    }

    public function test_undo_deletes_only_its_own_redemption(): void
    {
        $codes = $this->withEvents();
        $c = $codes->issue('q', [], maxUses: 2);
        $id = (string) $codes->check($c, 'q')->codeId;
        $codes->redeem($c, 'q', 'first');
        $this->events->on(CodeEvents::REDEEMED, static function (): void { throw new \RuntimeException('x'); });
        try {
            $codes->redeem($c, 'q', 'second');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $codes->get($id)->uses);
        $this->assertSame(['first'], array_column($codes->redemptions($id), 'redeemer'));
    }

    public function test_revoke_dispatches_revoked(): void
    {
        $codes = $this->withEvents();
        $got = [];
        $this->events->on(CodeEvents::REVOKED, function (array $p) use (&$got): void { $got[] = $p; }, 'q');
        $c = $codes->issue('q', ['k' => 'v']);
        $id = (string) $codes->check($c, 'q')->codeId;
        $codes->revoke($id);

        $this->assertCount(1, $got);
        $this->assertNotNull($got[0]['code']->revokedAt);
        $this->assertSame('q', $got[0]['purpose']);
        $this->assertNull($got[0]['redeemer']);
        $this->assertSame(['k' => 'v'], $got[0]['payload']);
    }

    public function test_revoke_listener_exception_propagates_and_revoke_stands(): void
    {
        $codes = $this->withEvents();
        $this->events->on(CodeEvents::REVOKED, static function (): void { throw new \RuntimeException('r'); });
        $id = (string) $codes->check($codes->issue('q', []), 'q')->codeId;
        try {
            $codes->revoke($id);
            $this->fail('expected');
        } catch (\RuntimeException) {
        }
        $this->assertNotNull($codes->get($id)->revokedAt);
    }

    public function test_unknown_event_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->events->on('codes.nope', static fn () => null);
    }
}
