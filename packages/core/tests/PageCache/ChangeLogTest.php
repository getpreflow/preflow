<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\ChangeLog;
use Preflow\Core\PageCache\DataLayerObserver;
use Preflow\Core\PageCache\DataTags;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\PageCache;

final class ChangeLogTest extends TestCase
{
    /** @var list<list<string>> */
    private array $calls = [];

    private function log(): ChangeLog
    {
        return new ChangeLog(function (array $tags): void {
            $this->calls[] = $tags;
        }, flushAtShutdown: false);
    }

    public function test_300_writes_of_one_type_are_one_merged_invalidation(): void
    {
        $log = $this->log();
        $observer = new DataLayerObserver(new DependencyRecorder(), $log);
        for ($i = 1; $i <= 300; $i++) {
            $observer->wrote('default', 'job', (string) $i);
        }
        $this->assertSame([], $this->calls, 'nothing is invalidated before the flush');

        $log->flush();
        $log->flush();

        $this->assertCount(1, $this->calls);
        $this->assertCount(302, $this->calls[0], '300 records, the listing and the storage, each once');
        $this->assertContains('default:job#1', $this->calls[0]);
        $this->assertContains('default:job#300', $this->calls[0]);
        $this->assertContains('default:job', $this->calls[0]);
        $this->assertContains('default:*', $this->calls[0]);
    }

    public function test_writes_after_a_flush_are_the_next_call(): void
    {
        $log = $this->log();
        $log->changed(['a']);
        $log->flush();
        $log->changed(['b', 'b']);
        $this->assertSame(['b'], $log->pending());
        $log->flush();

        $this->assertSame([['a'], ['b']], $this->calls);
    }

    public function test_a_failing_invalidation_is_logged_not_thrown(): void
    {
        $log = new ChangeLog(static fn (array $tags) => throw new \RuntimeException('busy'), flushAtShutdown: false);
        $log->changed(['a']);
        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $log->flush();
        } finally {
            ini_set('error_log', (string) $errorLog);
        }
        $this->assertSame([], $log->pending());
    }

    public function test_what_is_left_is_flushed_at_shutdown(): void
    {
        $dir = sys_get_temp_dir() . '/changelog_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $out = $dir . '/flushed';
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        file_put_contents($dir . '/run.php', '<?php require ' . var_export($autoload, true) . ';
            $log = new Preflow\\Core\\PageCache\\ChangeLog(static function (array $tags): void {
                file_put_contents(' . var_export($out, true) . ', implode(",", $tags));
            });
            $log->changed(["default:job#1", "default:job"]);
            $GLOBALS["log"] = $log;
            echo "done";');
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/run.php'), $output, $code);
            $this->assertSame(0, $code);
            $this->assertSame('default:job#1,default:job', @file_get_contents($out));
        } finally {
            @unlink($out);
            unlink($dir . '/run.php');
            rmdir($dir);
        }
    }

    public function test_observer_tags(): void
    {
        $log = $this->log();
        $recorder = new DependencyRecorder();
        $observer = new DataLayerObserver($recorder, $log);

        $observer->read('default', 'object__published', '5'); // not recording: ignored
        $recorder->start();
        $observer->read('default', 'Object__Published', '5');
        $observer->read('default', 'object__published', null);
        $observer->rawRead('default', 'SELECT * FROM `object__published` o JOIN `member` m ON m.uuid = o.member');
        $observer->rawRead('default', 'WITH t AS (SELECT 1) SELECT * FROM t');
        $this->assertSame([
            'default:object__published#5', 'default:object__published#', 'default:object__published',
            'default:member', 'default:*',
        ], array_keys($recorder->stop()));

        $observer->wrote('default', 'object__published', null);
        $observer->rawWrote('default', 'UPDATE likes SET n = n + 1');
        $this->assertSame([
            'default:object__published', 'default:object__published#', 'default:*',
            'default:likes', 'default:likes#',
        ], $log->pending());

        $observer->rawWrote('default', 'CALL anything()');
        $this->assertContains('*', $log->pending(), 'an unsure raw write is everything');
    }

    public function test_page_cache_changed_expands_tags_and_does_nothing_when_off(): void
    {
        (new PageCache())->changed('default:member');
        $this->assertFalse((new PageCache())->enabled());

        $log = $this->log();
        $cache = new PageCache($log);
        $cache->changed('default:member', 'default:job#7', 'menu');
        $this->assertTrue($cache->enabled());
        $this->assertSame([
            'default:member', 'default:member#', 'default:*',
            'default:job#7', 'default:job', 'menu',
        ], $log->pending());

        $this->assertSame(['*'], DataTags::changes('default:*'));
        $this->assertSame(['*'], DataTags::changes('*'));
        $this->assertSame(['default:job', 'default:job#', 'default:*'], DataTags::changes('default:job#'));
    }
}
