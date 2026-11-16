<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Limits;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Preflow\Ai\Limits\AiLimitReached;
use Preflow\Ai\Limits\AiLimits;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\DataTestCase;

/** Per-person limits for AI work, counted through core's Throttle on every built-in storage. */
final class AiLimitsTest extends DataTestCase
{
    private int $now = 1_000_000;

    private string $storage = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate($this->getPdo());
    }

    private function migrate(\PDO $pdo): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/core/migrations/*preflow_throttle*.php') ?: [] as $file) {
            /** @var Migration $migration */
            $migration = require $file;
            $migration->up(new Schema($pdo));
        }
    }

    private function onMysql(): void
    {
        $this->migrate($this->useMysql()->pdo());
        $this->storage = 'mysql';
    }

    private function throttle(): Throttle
    {
        return new Throttle($this->dataManager(), 'secret', $this->storage, fn (): int => $this->now);
    }

    /** @param array<string, array<string, mixed>> $limits */
    private function limits(array $limits = AiLimits::DEFAULTS): AiLimits
    {
        return new AiLimits($this->throttle(), $limits);
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['json' => ['json'], 'sqlite' => ['sqlite']];
    }

    /** The throttle rows stored, read straight from the storage (any purpose, any person). */
    private function rows(): int
    {
        return count($this->dataManager()->queryIn($this->throttle()->definition())->get());
    }

    #[DataProvider('storages')]
    public function test_take_allows_max_then_refuses_with_retry_after(string $storage = 'sqlite'): void
    {
        $this->storage = $storage;
        $limits = $this->limits(['draft' => ['max' => 2]]);
        $limits->take(AiLimits::DRAFT, 'p1');
        $this->now += 600;
        $limits->take(AiLimits::DRAFT, 'p1');

        try {
            $limits->take(AiLimits::DRAFT, 'p1');
            $this->fail('expected AiLimitReached');
        } catch (AiLimitReached $e) {
            $this->assertSame('draft', $e->action);
            $this->assertSame(2, $e->max);
            $this->assertSame(3000, $e->retryAfter, 'until the window that began with the first draft ends');
            $this->assertGreaterThanOrEqual(1, $e->retryAfter);
            $this->assertLessThanOrEqual(3600, $e->retryAfter);
            $this->assertSame('ai.limits.draft', $e->textKey());
            $this->assertSame(['max' => 2, 'minutes' => 50], $e->textParams());
        }

        $this->now += 3000;
        $limits->take(AiLimits::DRAFT, 'p1');
        $this->addToAssertionCount(1);
    }

    /** A refused take() counts (one atomic step), but never moves the window's end: the wait stays the same. */
    public function test_a_refused_take_does_not_prolong_the_wait(): void
    {
        $limits = $this->limits(['draft' => ['max' => 1]]);
        $limits->take(AiLimits::DRAFT, 'p1');
        $waits = [];
        foreach ([0, 100, 100] as $later) {
            $this->now += $later;
            try {
                $limits->take(AiLimits::DRAFT, 'p1');
                $this->fail('refused');
            } catch (AiLimitReached $e) {
                $waits[] = $e->retryAfter;
            }
        }

        $this->assertSame([3600, 3500, 3400], $waits);
        $this->now += 3400;
        $limits->take(AiLimits::DRAFT, 'p1');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('storages')]
    public function test_a_limit_of_zero_is_off(string $storage = 'sqlite'): void
    {
        $this->storage = $storage;
        $limits = $this->limits(['draft' => ['max' => 0], 'translate' => ['max' => 0]]);
        for ($i = 0; $i < 50; $i++) {
            $limits->take(AiLimits::DRAFT, 'p1');
            $limits->check(AiLimits::TRANSLATE, 'p1');
        }

        $this->assertNull($limits->limit(AiLimits::DRAFT));
        $this->assertSame(0, $this->rows(), 'no throttle row written');

        // The count reads real rows: one take of a limit that is on writes one.
        $this->limits(['draft' => ['max' => 5]])->take(AiLimits::DRAFT, 'p1');
        $this->assertSame(1, $this->rows());
    }

    #[DataProvider('storages')]
    public function test_check_counts_nothing(string $storage = 'sqlite'): void
    {
        $this->storage = $storage;
        $limits = $this->limits(['draft' => ['max' => 2]]);
        for ($i = 0; $i < 5; $i++) {
            $limits->check(AiLimits::DRAFT, 'p1');
        }
        $limits->take(AiLimits::DRAFT, 'p1');
        $limits->take(AiLimits::DRAFT, 'p1');

        try {
            $limits->check(AiLimits::DRAFT, 'p1');
            $this->fail('no budget left: check refuses');
        } catch (AiLimitReached $e) {
            $this->assertSame(2, $e->max);
            $this->assertSame(3600, $e->retryAfter);
        }
    }

    #[DataProvider('storages')]
    public function test_persons_and_actions_are_counted_apart(string $storage = 'sqlite'): void
    {
        $this->storage = $storage;
        $limits = $this->limits(['draft' => ['max' => 1], 'translate' => ['max' => 1]]);
        $limits->take(AiLimits::DRAFT, 'p1');
        $limits->take(AiLimits::DRAFT, 'p2');
        $limits->take(AiLimits::TRANSLATE, 'p1');
        $limits->take(AiLimits::TRANSLATE, 'p2');

        $this->expectException(AiLimitReached::class);
        $limits->take(AiLimits::TRANSLATE, 'p1');
    }

    public function test_a_limit_that_is_on_needs_the_app_key(): void
    {
        foreach ([Throttle::DEFAULT_KEY, ''] as $key) {
            $throttle = new Throttle($this->dataManager(), $key, $this->storage, fn (): int => $this->now);
            $message = null;
            $limits = new AiLimits($throttle, ['draft' => ['max' => 0]]); // building it needs no key
            try {
                $limits->take(AiLimits::TRANSLATE, 'p1');
            } catch (\Preflow\Ai\Limits\AiLimitsMisconfigured $e) {
                $message = $e->getMessage();
                $this->assertSame('APP_KEY', $e->setting);
            }
            $this->assertSame(0, $this->rows(), 'refused, not counted, never unlimited');
            try {
                $limits->assertKey();
                $this->fail('assertKey() refuses too');
            } catch (\Preflow\Ai\Limits\AiLimitsMisconfigured) {
            }
            $this->assertNotNull($message, "key \"{$key}\"");
            $this->assertStringContainsString('APP_KEY', $message);
            $this->assertStringContainsString('ai.limits.translate.max', $message, 'names the limit that is on');
            $this->assertStringNotContainsString('ai.limits.draft.max', $message);

            // Every limit off: no key needed, nothing limited.
            $off = new AiLimits($throttle, ['draft' => ['max' => 0], 'translate' => ['max' => 0]]);
            $off->take(AiLimits::TRANSLATE, 'p1');
            $this->assertSame(0, $this->rows());
        }
    }

    public function test_an_invalid_limit_names_the_setting(): void
    {
        foreach ([
            [['draft' => ['max' => -1]], 'ai.limits.draft.max'],
            [['translate' => ['max' => '5']], 'ai.limits.translate.max'],
            [['draft' => ['per' => 0]], 'ai.limits.draft.per'],
            [['translate' => ['max' => 5, 'per' => 1.5]], 'ai.limits.translate.per'],
        ] as [$config, $setting]) {
            try {
                $this->limits($config);
                $this->fail("expected {$setting} to be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($setting, $e->getMessage());
            }
        }
    }

    public function test_config_is_merged_over_the_defaults_and_unknown_actions_are_refused(): void
    {
        $limits = $this->limits(['translate' => ['max' => 5]]);
        $this->assertSame(['max' => 5, 'per' => 3600], $limits->limit(AiLimits::TRANSLATE));
        $this->assertSame(['max' => 30, 'per' => 3600], $limits->limit(AiLimits::DRAFT));

        $this->expectException(\InvalidArgumentException::class);
        $limits->take('image', 'p1');
    }

    #[Group('mysql')]
    public function test_take_allows_max_then_refuses_with_retry_after_on_mysql(): void
    {
        $this->onMysql();
        $this->test_take_allows_max_then_refuses_with_retry_after('mysql');
    }

    #[Group('mysql')]
    public function test_a_limit_of_zero_is_off_on_mysql(): void
    {
        $this->onMysql();
        $this->test_a_limit_of_zero_is_off('mysql');
    }

    #[Group('mysql')]
    public function test_check_counts_nothing_on_mysql(): void
    {
        $this->onMysql();
        $this->test_check_counts_nothing('mysql');
    }

    #[Group('mysql')]
    public function test_persons_and_actions_are_counted_apart_on_mysql(): void
    {
        $this->onMysql();
        $this->test_persons_and_actions_are_counted_apart('mysql');
    }
}
