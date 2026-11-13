<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Publishing\Console\PublishCommand;
use Preflow\Publishing\Console\PublishScheduledCommand;

final class PublishCommandsTest extends PublishingAppTestCase
{
    public function test_the_package_lists_its_commands(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([PublishCommand::class, PublishScheduledCommand::class], $composer['extra']['preflow']['commands'] ?? null);
        $this->assertSame('publish', (new PublishCommand())->getName());
        $this->assertSame('publish:scheduled', (new PublishScheduledCommand())->getName());
    }

    public function test_scheduled_publishes_a_due_record_and_leaves_a_future_one(): void
    {
        $this->working('post', 'due', ['title' => ['en' => 'Due'], '_publish_at' => '2000-01-01T00:00']);
        $this->working('post', 'later', ['title' => ['en' => 'Later'], '_publish_at' => '2999-01-01T00:00']);

        [$code, $out] = $this->runCommand(new PublishScheduledCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Published post/due', $out);
        $this->assertNotNull($this->publisher()->snapshot('post', 'due'));
        $this->assertSame('', $this->stored('post', 'due')->get('_publish_at'), 'publishing clears the schedule');
        $this->assertNull($this->publisher()->snapshot('post', 'later'));
        $this->assertSame('2999-01-01T00:00', $this->stored('post', 'later')->get('_publish_at'));

        [, $out] = $this->runCommand(new PublishScheduledCommand(fn () => $this->app()));
        $this->assertStringContainsString('Nothing due.', $out);
    }

    public function test_a_concurrent_scheduled_run_is_locked_out(): void
    {
        $this->working('post', 'due', ['title' => ['en' => 'Due'], '_publish_at' => '2000-01-01T00:00']);
        @mkdir($this->dir . '/storage/locks', 0777, true);
        $held = fopen($this->dir . '/storage/locks/publish-scheduled.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        [$code, $out] = $this->runCommand(new PublishScheduledCommand(fn () => $this->app()));

        flock($held, LOCK_UN);
        fclose($held);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Already running.', $out);
        $this->assertNull($this->publisher()->snapshot('post', 'due'));
    }

    public function test_publish_publishes_the_drafts_of_a_type(): void
    {
        $this->working('post', 'a', ['title' => ['en' => 'A']]);
        $this->publisher()->publish($this->working('post', 'b', ['title' => ['en' => 'B']]), null);
        $changed = $this->stored('post', 'b');
        $changed->set('title', ['en' => 'Unfinished']);
        $this->publisher()->saveDraft($changed, null);

        [$code, $out] = $this->runCommand(new PublishCommand(fn () => $this->app()), ['post']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Published post/a', $out);
        $this->assertStringContainsString('Skipped post/b (already published', $out);
        $this->assertSame(['en' => 'B'], $this->publisher()->snapshot('post', 'b')?->get('title'));

        $this->runCommand(new PublishCommand(fn () => $this->app()), ['post', '--all', '--id=b']);
        $this->assertSame(['en' => 'Unfinished'], $this->publisher()->snapshot('post', 'b')?->get('title'));

        [$code, $out] = $this->runCommand(new PublishCommand(fn () => $this->app()), ['note']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('"note" is not a publishing type', $out);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCommand(CommandInterface $command, array $args = []): array
    {
        ob_start();
        $code = $command->execute($args);

        return [$code, (string) ob_get_clean()];
    }
}
