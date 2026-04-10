<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\TokenPayload;
use Preflow\Core\Exceptions\SecurityException;

final class ComponentTokenTest extends TestCase
{
    private ComponentToken $token;

    protected function setUp(): void
    {
        $this->token = new ComponentToken('test-secret-key-32-chars-long!!');
    }

    public function test_encode_returns_string(): void
    {
        $encoded = $this->token->encode('App\\Components\\Hero', ['id' => '1']);

        $this->assertIsString($encoded);
        $this->assertNotEmpty($encoded);
    }

    public function test_decode_returns_payload(): void
    {
        $encoded = $this->token->encode('App\\Components\\Hero', ['id' => '1'], 'refresh');

        $payload = $this->token->decode($encoded);

        $this->assertInstanceOf(TokenPayload::class, $payload);
        $this->assertSame('App\\Components\\Hero', $payload->componentClass);
        $this->assertSame(['id' => '1'], $payload->props);
        $this->assertSame('refresh', $payload->action);
    }

    public function test_round_trip_preserves_data(): void
    {
        $class = 'App\\Widgets\\GameCard';
        $props = ['game_id' => '42', 'category' => 'strategy'];
        $action = 'toggle';

        $encoded = $this->token->encode($class, $props, $action);
        $decoded = $this->token->decode($encoded);

        $this->assertSame($class, $decoded->componentClass);
        $this->assertSame($props, $decoded->props);
        $this->assertSame($action, $decoded->action);
    }

    public function test_default_action_is_render(): void
    {
        $encoded = $this->token->encode('App\\X');

        $decoded = $this->token->decode($encoded);

        $this->assertSame('render', $decoded->action);
    }

    public function test_tampered_token_throws(): void
    {
        $encoded = $this->token->encode('App\\X');

        // Tamper with the token
        $tampered = $encoded . 'x';

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid component token');
        $this->token->decode($tampered);
    }

    public function test_wrong_key_throws(): void
    {
        $encoded = $this->token->encode('App\\X');

        $otherToken = new ComponentToken('different-secret-key-32-chars!!');

        $this->expectException(SecurityException::class);
        $this->token = $otherToken;
        $otherToken->decode($encoded);
    }

    public function test_expired_token_throws(): void
    {
        // Create a token encoder that produces expired timestamps
        $token = new class('test-secret-key-32-chars-long!!') extends ComponentToken {
            protected function currentTime(): int
            {
                return time() - 100000; // 100k seconds ago
            }
        };

        $encoded = $token->encode('App\\X');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('expired');
        $this->token->decode($encoded, maxAge: 3600);
    }

    public function test_non_expired_token_passes(): void
    {
        $encoded = $this->token->encode('App\\X');

        $decoded = $this->token->decode($encoded, maxAge: 86400);

        $this->assertSame('App\\X', $decoded->componentClass);
    }

    public function test_timestamp_included(): void
    {
        $before = time();
        $encoded = $this->token->encode('App\\X');
        $after = time();

        $decoded = $this->token->decode($encoded);

        $this->assertGreaterThanOrEqual($before, $decoded->timestamp);
        $this->assertLessThanOrEqual($after, $decoded->timestamp);
    }

    public function test_empty_props_encoded(): void
    {
        $encoded = $this->token->encode('App\\X', []);
        $decoded = $this->token->decode($encoded);

        $this->assertSame([], $decoded->props);
    }
}
