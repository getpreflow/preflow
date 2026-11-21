<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Publishing\PreviewLinks;

final class PreviewLinksTest extends TestCase
{
    public function test_round_trip(): void
    {
        $links = new PreviewLinks('secret-key-0123456789abcdefghijklmnop', 100, fn (): int => 1000);
        $target = $links->verify($links->create('post', 'p1', 'n1'));

        $this->assertSame(['post', 'p1', 'n1', 1100], [$target?->type, $target?->id, $target?->nonce, $target?->expiresAt]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = (new PreviewLinks('secret-key-0123456789abcdefghijklmnop', 100, fn (): int => 1000))->create('post', 'p1', 'n1');

        $this->assertNull((new PreviewLinks('secret-key-0123456789abcdefghijklmnop', 100, fn (): int => 1101))->verify($token));
    }

    public function test_other_key_or_tampering_is_rejected(): void
    {
        $token = (new PreviewLinks('secret-key-0123456789abcdefghijklmnop'))->create('post', 'p1', 'n1');

        $this->assertNull((new PreviewLinks('other-key-0123456789abcdefghijklmnopq'))->verify($token));
        $this->assertNull((new PreviewLinks('secret-key-0123456789abcdefghijklmnop'))->verify(substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A')));
    }

    public function test_verify_rejects_garbage(): void
    {
        $links = new PreviewLinks('secret-key-0123456789abcdefghijklmnop');
        foreach (['', 'a.b', '...', '!!!.???', 'eyJ4IjoxfQ.' . str_repeat('A', 43)] as $garbage) {
            $this->assertNull($links->verify($garbage), $garbage);
        }
    }

    /** Only a key that can sign (AppKey::canSign(): 32 bytes, not a public example) enables share links (since 0.81.0). */
    public function test_links_are_disabled_with_a_key_that_cannot_sign(): void
    {
        $this->assertFalse((new PreviewLinks('change-this-to-a-random-32-char-string!'))->enabled(), 'the skeleton\'s example key');
        $this->assertFalse((new PreviewLinks(str_repeat('k', 31)))->enabled(), 'a 31-byte key');
        $this->assertTrue((new PreviewLinks(str_repeat('k', 32)))->enabled(), 'a 32-byte key');
    }

    public function test_links_disabled_with_empty_or_default_key(): void
    {
        $this->assertFalse((new PreviewLinks(''))->enabled());
        $this->assertFalse((new PreviewLinks('preflow-default-key-change-me!!'))->enabled());
        $this->assertTrue((new PreviewLinks('a-real-key-0123456789abcdefghijklmnop'))->enabled());
    }
}
