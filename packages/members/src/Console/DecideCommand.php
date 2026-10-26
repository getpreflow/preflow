<?php

declare(strict_types=1);

namespace Preflow\Members\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Members\MemberState;
use Preflow\Notify\TransportException;

/**
 * members:decide <email> accept|decline: the owner's decision for a site that has OwnerApproval
 * but not Folio's Decision panel. It answers a member who waits for a decision (confirmed their
 * email, not decided yet): the accepted / declined mail goes out first, with the default message
 * (Members settings, else the lang files', in the member's language), and only when it was sent
 * does Members::decide() change the role and raise MemberEvents::DECIDED. Prints no address.
 * The package lists it in composer.json (extra.preflow.commands), so it needs no setup.
 */
final class DecideCommand implements CommandInterface
{
    private const string USAGE = "Usage: members:decide <email> accept|decline\n";

    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'members:decide'; }
    public function getDescription(): string { return 'Accept or decline a member who waits for approval: members:decide <email> accept|decline'; }

    public function execute(array $args): int
    {
        $args = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '-')));
        $decision = $args[1] ?? '';
        if (count($args) !== 2 || !in_array($decision, ['accept', 'decline'], true)) {
            echo self::USAGE;

            return 1;
        }

        $c = $this->app()->container();
        $members = $c->get(Members::class);
        $member = $members->findByEmail(Members::normalizeEmail($args[0]));
        if ($member === null) {
            echo "No member with that address.\n";

            return 1;
        }
        $state = MemberState::fromRecord($member);
        if ($state === MemberState::Unconfirmed) {
            echo "That member has not confirmed their email yet; there is nothing to decide.\n";

            return 1;
        }
        if ($state !== MemberState::Waiting) {
            echo "That member is not waiting for a decision (state: {$state->value}).\n";

            return 1;
        }

        $mailer = $c->get(MemberMailer::class);
        $accept = $decision === 'accept';
        try {
            $accept
                ? $mailer->accepted($member, $mailer->defaultMessage($member, 'accepted'))
                : $mailer->declined($member, $mailer->defaultMessage($member, 'declined'));
        } catch (TransportException) {
            echo "The mail could not be sent, so nothing was changed.\n";

            return 1;
        }
        $members->decide($member, $accept ? 'member' : 'rejected');
        echo $accept ? "Accepted; the member was told by mail.\n" : "Declined; the member was told by mail.\n";

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
}
