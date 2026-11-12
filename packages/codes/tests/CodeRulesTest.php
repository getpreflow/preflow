<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Preflow\Codes\CodeRefused;
use Preflow\Codes\Codes;

/** The readable-code check and the editing rules in the core, which Folio's code admin uses too. */
final class CodeRulesTest extends CodesAppTestCase
{
    public function test_readable_check_matches_issue(): void
    {
        $codes = $this->codes();

        $this->assertSame('SMARTSTACK2026', $codes->checkReadable('p', ' smart-stack 2026 '));
        $this->assertSame('SMARTSTACK2026', $codes->issue('p', code: ' smart-stack 2026 '));

        $refusals = [
            ['nope', 'ABCD1234', CodeRefused::UNKNOWN_PURPOSE],
            ['q', 'ABCD1234', CodeRefused::NOT_READABLE],
            ['p', 'ab', CodeRefused::FORMAT],
            ['p', ' - ', CodeRefused::FORMAT],
            ['p', "\xff\xfe", CodeRefused::FORMAT],
            ['p', 'smart stack-2026', CodeRefused::TAKEN],
        ];
        foreach ($refusals as [$purpose, $code, $reason]) {
            $messages = [];
            foreach ([
                fn () => $codes->checkReadable($purpose, $code),
                fn () => $codes->issue($purpose, code: $code),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("[{$purpose}] [{$code}] should be refused");
                } catch (\InvalidArgumentException $e) {
                    $this->assertInstanceOf(CodeRefused::class, $e);
                    $this->assertSame($reason, $e->reason);
                    $messages[] = $e->getMessage();
                }
            }
            $this->assertSame($messages[0], $messages[1], 'issue() and checkReadable() give the same words');
        }
    }

    public function test_prepare_fills_the_new_code_columns(): void
    {
        $codes = $this->codes();

        $readable = $codes->prepare('p', 'ab-cd 12');
        $this->assertSame(['purpose' => 'p', 'code' => 'ABCD12', 'form' => 'readable', 'expires_at' => null, 'uses' => 0, 'revoked_at' => null, 'created_at' => $this->now->getTimestamp()], $readable);

        $random = $codes->prepare('t', null);
        $this->assertSame('random', $random['form']);
        $this->assertSame($this->now->modify('+30 days')->format('Y-m-d\TH:i'), $random['expires_at'], 'the purpose ttl');
        $this->assertNotSame('', $random['code']);

        $given = $this->now->modify('+2 days');
        $this->assertSame($given->format('Y-m-d\TH:i'), $codes->prepare('t', null, $given)['expires_at']);
    }

    public function test_update_changes_label_expiry_and_max_uses_and_keeps_the_uses(): void
    {
        $codes = $this->codes();
        $code = $codes->issue('p', code: 'BOOKCODE1', label: 'Book');
        $id = (string) $codes->record($code, 'p')->id;
        $codes->redeem($code, 'p', 'anna');
        $until = $this->now->modify('+9 days');

        $codes->update($id, 'New label', $until, 5);

        $record = $codes->get($id);
        $this->assertEquals($until->setTime((int) $until->format('H'), (int) $until->format('i')), $record->expiresAt);
        $this->assertSame(5, $record->maxUses);
        $this->assertSame('New label', $record->label);
        $this->assertSame(1, $record->uses);
        $codes->update($id, 'New label', null, null);
        $this->assertNull($codes->get($id)->expiresAt);
        $this->assertNull($codes->get($id)->maxUses);
    }

    public function test_update_refuses_revoked_and_past_expiry_and_low_max_uses(): void
    {
        $codes = $this->codes();
        $open = $codes->issue('q', maxUses: 5);
        $openId = (string) $codes->record($open, 'q')->id;
        $codes->redeem($open, 'q');
        $codes->redeem($open, 'q');
        $revoked = $codes->issue('q');
        $revokedId = (string) $codes->record($revoked, 'q')->id;
        $codes->revoke($revokedId);
        $once = $codes->issue('q', maxUses: 1);
        $onceId = (string) $codes->record($once, 'q')->id;
        $codes->redeem($once, 'q');
        $future = $this->now->modify('+1 day');

        $cases = [
            [$revokedId, $future, null, CodeRefused::REVOKED],
            [$onceId, $future, 3, CodeRefused::USED_UP],
            [$openId, $this->now->modify('-1 day'), 5, CodeRefused::EXPIRY_PAST],
            [$openId, $this->now, 5, CodeRefused::EXPIRY_PAST],
            [$openId, $future, 1, CodeRefused::MAX_USES_LOW],
            ['nope', $future, 1, CodeRefused::UNKNOWN_CODE],
        ];
        foreach ($cases as [$id, $at, $max, $reason]) {
            try {
                $codes->update($id, 'x', $at, $max);
                $this->fail("update should refuse ({$reason})");
            } catch (CodeRefused $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->assertSame('', $codes->get($openId)->label, 'a refused update writes nothing');
        $codes->update($openId, 'ok', $future, 2); // as many as used so far is allowed

        $this->assertSame(2, $codes->get($openId)->maxUses);
    }

    public function test_update_keeps_an_expiry_the_code_already_has(): void
    {
        $codes = $this->codes();
        $code = $codes->issue('q', expiresAt: $this->now->modify('+1 hour'));
        $id = (string) $codes->record($code, 'q')->id;
        $at = $codes->get($id)->expiresAt;
        $this->now = $this->now->modify('+2 hours');

        $codes->update($id, 'Only the label', $at, null); // expired already, still editable

        $this->assertEquals($at, $codes->get($id)->expiresAt);
    }

    public function test_update_keeps_bound_code_single_use(): void
    {
        $codes = $this->codes();
        $code = $codes->issue('q', recipient: 'anna@example.org');
        $id = (string) $codes->record($code, 'q')->id;

        foreach ([null, 9, 2] as $max) {
            try {
                $codes->update($id, 'Invite', null, $max);
                $this->fail('a bound code keeps one use');
            } catch (CodeRefused $e) {
                $this->assertSame(CodeRefused::BOUND_SINGLE_USE, $e->reason);
            }
        }
        $codes->update($id, 'Invite', null, 1);

        $this->assertSame(1, $codes->get($id)->maxUses);
    }

    public function test_check_update_writes_nothing_and_refuses_like_update(): void
    {
        $codes = $this->codes();
        $code = $codes->issue('q', label: 'Keep', maxUses: 5);
        $id = (string) $codes->record($code, 'q')->id;

        $codes->checkUpdate($id, 'Other', $this->now->modify('+1 day'), 3);
        $this->assertSame('Keep', $codes->get($id)->label);
        $this->assertSame(5, $codes->get($id)->maxUses);
        $this->expectException(CodeRefused::class);
        $codes->checkUpdate($id, 'Other', $this->now->modify('-1 day'), 3);
    }

    public function test_the_write_itself_refuses_a_code_that_changed_meanwhile(): void
    {
        $codes = $this->codes();
        $code = $codes->issue('q', maxUses: 5);
        $id = (string) $codes->record($code, 'q')->id;
        $codes->redeem($code, 'q');
        $codes->redeem($code, 'q');
        $write = new \ReflectionMethod($codes, 'writeColumns');
        $guard = new \ReflectionMethod($codes, 'liveGuard');
        $live = fn (?int $max) => $write->invoke($codes, $id, ['label' => 'x'], static fn (): bool => true, $guard->invoke(null, $max));

        $this->assertFalse($live(1), 'two uses are already counted: max 1 is refused by the UPDATE itself');
        $this->assertTrue($live(2));
        $codes->revoke($id);
        $this->assertFalse($live(null), 'revoked meanwhile');
        $this->assertSame('x', $codes->get($id)->label);
    }

    public function test_codes_has_no_mailer(): void
    {
        $this->assertFalse(class_exists('Preflow\\Codes\\CodeMailer'));
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        foreach (['preflow/notify', 'preflow/view', 'preflow/i18n'] as $package) {
            $this->assertArrayNotHasKey($package, $composer['require']);
        }
        $this->assertDirectoryDoesNotExist(dirname(__DIR__) . '/lang');
        $this->assertDirectoryDoesNotExist(dirname(__DIR__) . '/templates');
        $this->assertInstanceOf(Codes::class, $this->codes());
    }
}
