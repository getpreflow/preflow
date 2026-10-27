<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Preflow\Core\Throttle\Throttle;

final class RecipientTest extends CodesAppTestCase
{
    public function test_bound_code_redeems_for_its_address_in_any_case_and_spacing(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', [], recipient: 'anna@example.org');

        $result = $codes->redeem($c, 'q', 'm1', recipient: ' Anna@Example.org ');
        $this->assertTrue($result->ok);
        $this->assertSame('anna@example.org', $result->recipient);
    }

    public function test_other_address_is_wrong_recipient_and_counts_no_use(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', [], recipient: 'anna@example.org');
        $id = (string) $codes->check($c, 'q')->codeId;

        $result = $codes->redeem($c, 'q', 'm1', 'client-1', 'bob@example.org');
        $this->assertFalse($result->ok);
        $this->assertSame('wrong_recipient', $result->reason);
        $this->assertSame($id, $result->codeId);
        $this->assertSame('anna@example.org', $result->recipient);
        $this->assertSame(0, $codes->get($id)->uses);
        $this->assertSame([], $codes->redemptions($id));

        $throttle = $this->app()->container()->get(Throttle::class);
        $this->assertTrue($throttle->tooMany('codes.q', 'client-1', 1, 900));
    }

    public function test_check_without_address_reports_recipient(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', [], recipient: 'anna@example.org');

        $check = $codes->check($c, 'q');
        $this->assertTrue($check->ok);
        $this->assertSame('anna@example.org', $check->recipient);

        $redeem = $codes->redeem($c, 'q', 'm1');
        $this->assertFalse($redeem->ok);
        $this->assertSame('wrong_recipient', $redeem->reason);
    }

    public function test_check_with_other_address_is_wrong_recipient(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', [], recipient: 'anna@example.org');

        $this->assertSame('wrong_recipient', $codes->check($c, 'q', recipient: 'bob@example.org')->reason);
        $this->assertTrue($codes->check($c, 'q', recipient: 'ANNA@example.org')->ok);
    }

    public function test_bound_code_has_one_use(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', [], maxUses: 5, recipient: 'anna@example.org');
        $id = (string) $codes->check($c, 'q')->codeId;

        $this->assertSame(1, $codes->get($id)->maxUses);
        $this->assertTrue($codes->redeem($c, 'q', recipient: 'anna@example.org')->ok);
        $this->assertSame('used_up', $codes->redeem($c, 'q', recipient: 'anna@example.org')->reason);
    }

    public function test_readable_code_cannot_be_bound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->codes()->issue('p', [], 'BOOK1234', recipient: 'anna@example.org');
    }

    public function test_recipient_needs_an_at_sign(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->codes()->issue('q', [], recipient: 'anna');
    }

    public function test_wrong_recipient_not_reported_for_dead_codes(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', [], recipient: 'anna@example.org');
        $codes->revoke((string) $codes->check($c, 'q')->codeId);

        $result = $codes->redeem($c, 'q', recipient: 'bob@example.org');
        $this->assertSame('revoked', $result->reason);
        $this->assertNull($result->recipient);
    }

    public function test_for_recipient_lists_open_codes_newest_first(): void
    {
        $codes = $this->codes();
        $address = 'anna@example.org';
        $ids = [];
        foreach (['revoked', 'expired', 'open'] as $i => $kind) {
            $this->now = new \DateTimeImmutable('2026-10-22 12:00:00')->modify('+' . $i . ' seconds');
            $code = $codes->issue('q', [], expiresAt: $kind === 'expired' ? $this->now->modify('+1 hour') : null, recipient: ' ANNA@example.org');
            $ids[$kind] = (string) $codes->check($code, 'q')->codeId;
        }
        $codes->revoke($ids['revoked']);
        $this->now = new \DateTimeImmutable('2026-10-22 12:00:10')->modify('+1 day');
        $codes->issue('q', [], recipient: 'other@example.org');
        // the open one was issued with no expiry; "now" moved a day, the expired one has passed
        $this->assertSame([$ids['open']], array_map(static fn ($r) => $r->id, $codes->forRecipient($address)));
        $this->assertSame(
            [$ids['open'], $ids['expired'], $ids['revoked']],
            array_map(static fn ($r) => $r->id, $codes->forRecipient('Anna@Example.org ', open: false)),
        );
        $this->assertSame([], $codes->forRecipient($address, 'p', open: false));
        $this->assertCount(3, $codes->forRecipient($address, 'q', open: false));
    }

    public function test_ttl_sets_expiry(): void
    {
        $codes = $this->codes();
        $a = (string) $codes->check($codes->issue('t'), 't')->codeId;
        $this->assertEquals($this->now->modify('+30 days'), $codes->get($a)->expiresAt);

        $explicit = $this->now->modify('+2 days');
        $b = $codes->issue('t', expiresAt: $explicit);
        $this->assertEquals($explicit, $codes->record($b, 't')->expiresAt);

        $c = $codes->issue('q');
        $this->assertNull($codes->record($c, 'q')->expiresAt);
        $this->assertSame(30, $codes->purpose('t')->ttl);
        $this->assertNull($codes->purpose('q')->ttl);
    }

    public function test_record_carries_the_stored_fields(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', ['x' => 1], recipient: 'anna@example.org');
        $record = $codes->record($c, 'q');
        $this->assertSame('q', $record->purpose);
        $this->assertSame($c, $record->code);
        $this->assertSame('anna@example.org', $record->recipient);
        $this->assertSame(['x' => 1], $record->payload);
        $this->assertSame('random', $record->form);
        $this->assertSame(0, $record->uses);
        $this->assertNull($record->revokedAt);
        $this->assertTrue($record->isOpen($this->now));
        $this->assertNull($codes->record('nope', 'q'));
        $this->assertNull($codes->get('nope'));
    }

    public function test_book_code_positional_calls_unchanged(): void
    {
        $codes = $this->codes();
        $codes->issue('p', [], 'BOOK1234');

        $this->assertTrue($codes->check('book-1234', 'p', 'client-1')->ok);
        $result = $codes->redeem('BOOK 1234', 'p', 'm1', 'client-1');
        $this->assertTrue($result->ok);
        $this->assertNull($result->recipient);
        $this->assertSame('unknown', $codes->redeem('NOPE0000', 'p', 'm1', 'client-1')->reason);
    }
}
