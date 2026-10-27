<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Preflow\Codes\CodeEvents;
use Preflow\Codes\CodeMailer;
use Preflow\Codes\Codes;
use Preflow\Notify\TransportFactory;

/**
 * The personal invitation end to end (spec section 7) with the container's own Codes, CodeEvents and
 * CodeMailer, no Folio and a capturing mail transport. The "app" is this test: its listener adds the
 * guest to a group, its route checks, then redeems.
 */
final class InvitationPathTest extends CodesAppTestCase
{
    /** @var array<string, list<string>> group => members, the app's own state */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Codes', 'debug' => 1, 'engine' => 'twig', 'key' => 'codes-app-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'fallback' => 'en', 'url_strategy' => 'prefix', 'cookie' => false];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'Site <hello@site.test>']]];\n");
        $this->write('config/codes.php', "<?php\nreturn " . var_export([
            'purposes' => ['bggenius.invite' => ['label' => 'Invitation', 'readable' => false, 'ttl' => 30]],
            'throttle' => ['max' => 10, 'window' => 900],
        ], true) . ";\n");
        $this->write('app/pages/index.twig', 'HOME');
    }

    public function test_invitation_from_mail_to_group_membership(): void
    {
        $c = $this->app()->container();
        $codes = $c->get(Codes::class);
        $c->get(CodeEvents::class)->on(CodeEvents::REDEEMED, function (array $e): void {
            $this->groups[$e['payload']['group']][] = (string) $e['redeemer'];
        }, 'bggenius.invite');

        // 1. The host invites Anna; the mail carries the personal link.
        $code = $codes->issue('bggenius.invite', ['group' => 'g1'], recipient: ' Anna@Example.org ');
        $record = $codes->record($code, 'bggenius.invite');
        $this->assertNotNull($record);
        $this->assertSame('anna@example.org', $record->recipient);
        $this->assertNotNull($record->expiresAt, 'the purpose ttl applies');
        $this->assertSame(1, $record->maxUses);
        $this->assertTrue($c->get(CodeMailer::class)->send($record, 'anna@example.org', '/invite/' . $code, ['message' => 'Come play', 'from_name' => 'Ben']));
        $sent = $c->get(TransportFactory::class)->get('mail')->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(['anna@example.org'], $sent[0]->message->to);
        $this->assertStringContainsString('https://site.test/invite/' . $code, $sent[0]->message->text);

        // 2. The click: check without an address tells whom the code is for.
        $check = $codes->check($code, 'bggenius.invite');
        $this->assertTrue($check->ok);
        $this->assertSame('anna@example.org', $check->recipient);

        // Signed in with another address: refused, no use spent, nobody added.
        $wrong = $codes->redeem($code, 'bggenius.invite', 'bob', recipient: 'bob@example.org');
        $this->assertFalse($wrong->ok);
        $this->assertSame('wrong_recipient', $wrong->reason);
        $this->assertSame([], $this->groups);

        // 3. The right address redeems; the app's listener adds her to the group.
        $this->assertCount(1, $codes->forRecipient('anna@example.org', 'bggenius.invite'));
        $ok = $codes->redeem($code, 'bggenius.invite', 'anna', recipient: 'Anna@example.org');
        $this->assertTrue($ok->ok);
        $this->assertSame(['g1' => ['anna']], $this->groups);

        // 4. Her profile: nothing open any more; the code is used up.
        $this->assertSame([], $codes->forRecipient('anna@example.org', 'bggenius.invite'));
        $this->assertSame('used_up', $codes->check($code, 'bggenius.invite')->reason);
    }

    public function test_revoked_and_renewed_invitations(): void
    {
        $codes = $this->app()->container()->get(Codes::class);

        $revoked = $codes->issue('bggenius.invite', ['group' => 'g2'], recipient: 'carl@example.org');
        $codes->revoke((string) $codes->record($revoked, 'bggenius.invite')?->id);
        $this->assertSame('revoked', $codes->check($revoked, 'bggenius.invite')->reason);
        $this->assertSame([], $codes->forRecipient('carl@example.org', 'bggenius.invite'));

        $expired = $codes->issue('bggenius.invite', ['group' => 'g3'], expiresAt: new \DateTimeImmutable('-1 day'), recipient: 'dora@example.org');
        $id = (string) $codes->record($expired, 'bggenius.invite')?->id;
        $this->assertSame('expired', $codes->check($expired, 'bggenius.invite')->reason);

        $codes->renew($id, new \DateTimeImmutable('+30 days'));
        $this->assertTrue($codes->check($expired, 'bggenius.invite')->ok);
        $this->assertTrue($codes->redeem($expired, 'bggenius.invite', 'dora', recipient: 'dora@example.org')->ok);
    }

    public function test_a_failing_listener_leaves_the_invitation_open(): void
    {
        $c = $this->app()->container();
        $codes = $c->get(Codes::class);
        $c->get(CodeEvents::class)->on(CodeEvents::REDEEMED, static function (): void {
            throw new \RuntimeException('group is full');
        });
        $code = $codes->issue('bggenius.invite', [], recipient: 'eve@example.org');

        try {
            $codes->redeem($code, 'bggenius.invite', 'eve', 'client-1', 'eve@example.org');
            $this->fail('The listener exception reaches the caller.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($codes->check($code, 'bggenius.invite')->ok);
        $this->assertCount(1, $codes->forRecipient('eve@example.org'));
    }
}
