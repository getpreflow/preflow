<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Console\FolioCommands;
use Preflow\Folio\Console\PublishCommand;
use Preflow\Folio\Console\PublishScheduledCommand;
use Preflow\Folio\Tests\Support\PublishingScaffold;

final class PublishCommandsTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_folio_lists_its_commands(): void
    {
        $this->assertContains(PublishScheduledCommand::class, FolioCommands::all());
        $this->assertContains(PublishCommand::class, FolioCommands::all());
    }

    public function test_scheduled_command_publishes_due_items(): void
    {
        $this->working('post', 'due', ['title' => ['en' => 'Due'], 'slug' => ['en' => 'due'], '_publish_at' => '2000-01-01T00:00']);

        [$code, $out] = $this->runCommand(new PublishScheduledCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Published post/due', $out);
        $this->assertNotNull($this->publisher()->snapshot('post', 'due'));
    }

    public function test_concurrent_run_is_locked_out(): void
    {
        $this->working('post', 'due', ['title' => ['en' => 'Due'], 'slug' => ['en' => 'due'], '_publish_at' => '2000-01-01T00:00']);
        @mkdir($this->dir . '/storage/locks', 0777, true);
        $held = fopen($this->dir . '/storage/locks/folio-publish-scheduled.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        [$code, $out] = $this->runCommand(new PublishScheduledCommand(fn () => $this->app()));

        flock($held, LOCK_UN);
        fclose($held);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Already running.', $out);
        $this->assertNull($this->publisher()->snapshot('post', 'due'));
    }

    public function test_publish_command_publishes_every_record(): void
    {
        $this->working('post', 'a', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a']]);
        $this->working('post', 'b', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b']]);
        $this->working('post', 'bad', ['title' => ['en' => ''], 'slug' => ['en' => 'bad']]);

        [$code, $out] = $this->runCommand(new PublishCommand(fn () => $this->app()), ['post']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Published post/a', $out);
        $this->assertStringContainsString('Published post/b', $out);
        $this->assertStringContainsString('Failed post/bad', $out);
        $this->assertNotNull($this->publisher()->snapshot('post', 'a'));
        $this->assertNull($this->publisher()->snapshot('post', 'bad'));
    }

    public function test_publish_command_single_id_and_bad_type(): void
    {
        $this->working('post', 'a', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a']]);
        $this->working('post', 'b', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b']]);

        [$code] = $this->runCommand(new PublishCommand(fn () => $this->app()), ['post', '--id=a']);
        $this->assertSame(0, $code);
        $this->assertNotNull($this->publisher()->snapshot('post', 'a'));
        $this->assertNull($this->publisher()->snapshot('post', 'b'));

        [$code, $out] = $this->runCommand(new PublishCommand(fn () => $this->app()), ['nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a publishing type', $out);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCommand(\Preflow\DevTools\Command\CommandInterface $command, array $args = []): array
    {
        ob_start();
        $code = $command->execute($args);

        return [$code, (string) ob_get_clean()];
    }
}
