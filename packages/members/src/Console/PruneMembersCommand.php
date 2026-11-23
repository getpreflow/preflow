<?php

declare(strict_types=1);

namespace Preflow\Members\Console;

use Preflow\Auth\Remember\RememberMe;
use Preflow\Core\Application;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Members\MemberState;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;

/**
 * Nightly from cron: unconfirmed accounts go after retention.unconfirmed_days (7) from registration,
 * declined ones after retention.declined_days (30) from the decision (config/members.php), and
 * expired tokens, remember rows, throttle hits and activity rows past their retention are pruned. Deletions go through Members::delete() (the self
 * rules, the DELETING / DELETED hooks); an account a restrict rule or a hook keeps is counted as kept. Prints counts only, never an email
 * or a name. A run that finds the lock held (storage/locks/members-prune.lock under the app's base path) prints a line naming it and exits 0. The package lists it in composer.json (extra.preflow.commands), so it needs no setup.
 */
final class PruneMembersCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'members:prune'; }
    public function getDescription(): string { return 'Delete stale unconfirmed and declined members, prune expired tokens (run from cron)'; }

    public function execute(array $args): int
    {
        $app = $this->app();

        $dir = $app->basePath('storage/locks');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, "members:prune — cannot create {$dir}\n");
            return 1;
        }
        $path = $dir . '/members-prune.lock';
        $lock = fopen($path, 'c');
        if ($lock === false) {
            fwrite(STDERR, "members:prune — cannot open {$path}\n");
            return 1;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            // A cron run that overlaps another is skipped, not failed: exit 0, but say so (since 0.84.1).
            echo "members:prune — skipped: another run holds {$path}\n";

            return 0;
        }

        try {
            $c = $app->container();
            $now = $this->now($args);
            $config = $c->get(MembersConfig::class);
            $members = $c->get(Members::class);

            $unconfirmed = $declined = $kept = 0;
            foreach ($c->get(DataManager::class)->queryType(MembersConfig::TYPE)->get() as $m) {
                if (!$m instanceof DynamicRecord) {
                    continue;
                }
                $state = MemberState::fromRecord($m);
                if ($state === MemberState::Unconfirmed && $this->expired($m->get('registered_at'), $config->retention('unconfirmed'), $now)
                    || $state === MemberState::Declined && $this->expired($m->get('decided_at'), $config->retention('declined'), $now)) {
                    // The member's own rules (onSelfDelete): no one asked, so a refusal tells no one; it is counted.
                    // A throwing DELETING listener keeps this account; the run goes on (the logged line names no address).
                    try {
                        $deleted = $members->delete($m, byMember: false)->deleted;
                    } catch (\Throwable $e) {
                        error_log(sprintf('members:prune: deleting member %s failed (%s)', (string) $m->getId(), $e::class));
                        $deleted = false;
                    }
                    if (!$deleted) {
                        $kept++;
                        continue;
                    }
                    $state === MemberState::Unconfirmed ? $unconfirmed++ : $declined++;
                }
            }

            $c->get(Tokens::class)->prune();
            $c->get(RememberMe::class)->prune();
            $c->get(Throttle::class)->prune();
            // The activity log's retention too (@since 0.77; activity:prune runs it on its own).
            // A failing log is a warning: the members' own pruning above stands and is reported.
            if ($c->bound(\Preflow\Core\Activity\ActivityLog::class)) {
                try {
                    $c->get(\Preflow\Core\Activity\ActivityLog::class)->prune();
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'members:prune — the activity log was not pruned: ' . $e->getMessage() . "\n");
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $refused = $kept > 0 ? "; kept {$kept} (refused by a self rule or a hook)" : '';
        echo "members:prune — deleted {$unconfirmed} unconfirmed, {$declined} declined{$refused}; pruned tokens, remember, throttle, activity\n";

        return 0;
    }

    /** The app in the working directory, booted. */
    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }
        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }

    /** @param list<string> $args --now=<ATOM> overrides the clock (tests) */
    private function now(array $args): \DateTimeImmutable
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--now=')) {
                return new \DateTimeImmutable(substr($arg, 6));
            }
        }

        return Contexts::clock()->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Strictly older than $days; a missing or unreadable date never expires (stored as TEXT, so compared here). */
    private function expired(mixed $stamp, int $days, \DateTimeImmutable $now): bool
    {
        if ($days <= 0 || !is_string($stamp) || $stamp === '') {
            return false;
        }
        try {
            $at = new \DateTimeImmutable($stamp);
        } catch (\Exception) {
            return false;
        }

        return $at < $now->modify("-{$days} days");
    }
}
