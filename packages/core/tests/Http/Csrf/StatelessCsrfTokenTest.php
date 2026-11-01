<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Csrf\StatelessCsrfToken;

final class StatelessCsrfTokenTest extends TestCase
{
    private const KEY = 'a-real-application-key-of-32-bytes';
    private const DAY = 86400;

    private function at(int $time, string $key = self::KEY): StatelessCsrfToken
    {
        return new StatelessCsrfToken($key, static fn (): int => $time);
    }

    public function test_current_and_previous_slot_verify(): void
    {
        $now = 100 * self::DAY + 500;
        $token = $this->at($now)->issue();

        $this->assertMatchesRegularExpression('/^v1\.100\.[A-Za-z0-9_-]+$/', $token);
        $this->assertTrue($this->at($now)->verify($token));
        $this->assertTrue($this->at($now + self::DAY)->verify($token));
    }

    public function test_two_slots_old_fails(): void
    {
        $now = 100 * self::DAY;
        $token = $this->at($now)->issue();

        $this->assertFalse($this->at($now + 2 * self::DAY)->verify($token));
    }

    public function test_future_slot_fails(): void
    {
        $now = 100 * self::DAY;
        $token = $this->at($now + self::DAY)->issue();

        $this->assertFalse($this->at($now)->verify($token));
    }

    public function test_wrong_key_fails(): void
    {
        $token = $this->at(1_000_000)->issue();

        $this->assertFalse($this->at(1_000_000, 'another-application-key-of-32-bytes')->verify($token));
    }

    public function test_tampered_signature_fails(): void
    {
        $token = $this->at(1_000_000)->issue();
        $last = substr($token, -1);
        $tampered = substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A');

        $this->assertFalse($this->at(1_000_000)->verify($tampered));
    }

    public function test_tampered_slot_fails(): void
    {
        $now = 100 * self::DAY;
        $token = $this->at($now)->issue();
        $forged = preg_replace('/^v1\.100\./', 'v1.101.', $token);

        $this->assertFalse($this->at($now + self::DAY)->verify($forged));
    }

    public function test_malformed_input_is_rejected_quietly(): void
    {
        $this->assertFalse($this->at(1_000_000)->verify(''));

        foreach (['x', 'v1', 'v1.', 'v1..', 'v1.abc.def', 'v2.11.abc', 'v1.11.a.b', "v1.11.\0", 'v1.-5.abc', str_repeat('a', 5000)] as $bad) {
            $this->assertFalse($this->at(1_000_000)->verify($bad), $bad);
        }
    }

    public function test_empty_and_default_key_disable_it(): void
    {
        foreach (['', StatelessCsrfToken::DEFAULT_KEY] as $key) {
            $disabled = new StatelessCsrfToken($key);

            $this->assertFalse($disabled->enabled());
            $this->assertFalse($disabled->verify($this->at(1_000_000)->issue()));

            try {
                $disabled->issue();
                $this->fail('issue() must throw when disabled');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_default_key_disables_issue_for_each_key(): void
    {
        $this->assertSame('preflow-default-key-change-me!!', StatelessCsrfToken::DEFAULT_KEY);
        $this->assertTrue((new StatelessCsrfToken(self::KEY))->enabled());
    }

    public function test_default_clock_is_wall_time(): void
    {
        $token = new StatelessCsrfToken(self::KEY);

        $this->assertTrue($token->verify($token->issue()));
    }

    public function test_looks_stateless_tells_session_tokens_apart(): void
    {
        $this->assertTrue(StatelessCsrfToken::looksStateless($this->at(1_000_000)->issue()));
        $this->assertFalse(StatelessCsrfToken::looksStateless(bin2hex(random_bytes(32))));
        $this->assertFalse(StatelessCsrfToken::looksStateless(''));
    }
}
