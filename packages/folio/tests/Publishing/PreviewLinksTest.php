<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Publishing\PreviewLinks;

final class PreviewLinksTest extends TestCase
{
    public function test_round_trip(): void
    {
        $links = new PreviewLinks('secret-key', 100, fn (): int => 1000);
        $target = $links->verify($links->create('post', 'p1', 'n1'));

        $this->assertSame(['post', 'p1', 'n1', 1100], [$target?->type, $target?->id, $target?->nonce, $target?->expiresAt]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = (new PreviewLinks('secret-key', 100, fn (): int => 1000))->create('post', 'p1', 'n1');

        $this->assertNull((new PreviewLinks('secret-key', 100, fn (): int => 1101))->verify($token));
    }

    public function test_other_key_or_tampering_is_rejected(): void
    {
        $token = (new PreviewLinks('secret-key'))->create('post', 'p1', 'n1');

        $this->assertNull((new PreviewLinks('other-key'))->verify($token));
        $this->assertNull((new PreviewLinks('secret-key'))->verify(substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A')));
    }

    public function test_verify_rejects_garbage(): void
    {
        $links = new PreviewLinks('secret-key');
        foreach (['', 'a.b', '...', '!!!.???', 'eyJ4IjoxfQ.' . str_repeat('A', 43)] as $garbage) {
            $this->assertNull($links->verify($garbage), $garbage);
        }
    }

    public function test_links_disabled_with_empty_or_default_key(): void
    {
        $this->assertFalse((new PreviewLinks(''))->enabled());
        $this->assertFalse((new PreviewLinks('preflow-default-key-change-me!!'))->enabled());
        $this->assertTrue((new PreviewLinks('a-real-key'))->enabled());
    }
}
