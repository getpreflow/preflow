<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\OldInput;
use Preflow\Testing\ArraySession;

final class OldInputTest extends TestCase
{
    public function test_old_input_never_keeps_passwords_tokens_or_files(): void
    {
        $session = new ArraySession();
        $session->start();
        $file = (new Psr17Factory())->createUploadedFile((new Psr17Factory())->createStream('x'), 1, UPLOAD_ERR_OK, 'cv.pdf');

        OldInput::keep($session, [
            'name' => 'Ada',
            'password' => 'x',
            'new_password_confirm' => 'y',
            '_csrf_token' => 't',
            'profile' => ['password' => 'z', 'city' => 'Bern', 'Old_PASSWORD' => 'w'],
            'cv' => $file,
            'attachments' => [$file],
        ]);
        $session->ageFlash();

        $old = new OldInput(static fn () => $session);
        $this->assertSame(['name' => 'Ada', 'profile' => ['city' => 'Bern']], $old->all());
        $this->assertSame('Ada', $old->get('name'));
        $this->assertSame('none', $old->get('email', 'none'));
    }

    public function test_a_large_body_is_not_kept(): void
    {
        $session = new ArraySession();
        $session->start();
        $old = new OldInput(static fn () => $session);

        // Anyone can reach the expired-session answer: a big body must not fill a session.
        OldInput::keep($session, ['name' => 'Ada', 'text' => str_repeat('x', 70 * 1024)]);
        $session->ageFlash();
        $this->assertSame([], $old->all());

        OldInput::keep($session, ['rows' => array_fill(0, 20000, 'a')]);
        $session->ageFlash();
        $this->assertSame([], $old->all());

        OldInput::keep($session, ['text' => str_repeat('x', 60 * 1024)]);
        $session->ageFlash();
        $this->assertSame(60 * 1024, strlen($old->get('text')));
    }

    public function test_empty_without_a_started_session(): void
    {
        $this->assertSame([], (new OldInput(static fn () => null))->all());
        $this->assertSame([], (new OldInput(static fn () => new ArraySession()))->all());
    }

    public function test_kept_for_the_next_request_only(): void
    {
        $session = new ArraySession();
        $session->start();
        OldInput::keep($session, ['name' => 'Ada']);
        $old = new OldInput(static fn () => $session);

        $session->ageFlash();
        $this->assertSame(['name' => 'Ada'], $old->all());
        $session->ageFlash();
        $this->assertSame([], $old->all());
    }
}
