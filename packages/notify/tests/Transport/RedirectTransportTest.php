<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Email;
use Preflow\Notify\Sms;
use Preflow\Notify\Transport\InMemoryTransport;
use Preflow\Notify\Transport\RedirectTransport;
use Preflow\Notify\TransportFactory;

final class RedirectTransportTest extends TestCase
{
    public function test_email_goes_only_to_the_redirect_address(): void
    {
        $inner = new InMemoryTransport('smtp');
        $transport = new RedirectTransport($inner, ['me@stage.test']);

        $sent = $transport->send(new Email(
            to: ['Ann <ann@example.test>', 'bob@example.test'],
            subject: 'Welcome',
            text: 'Hello',
            cc: 'cc@example.test',
            bcc: 'bcc@example.test',
            replyTo: 'office@example.test',
            from: 'no-reply@example.test',
        ));

        $email = $inner->sent()[0]->message;
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame(['me@stage.test'], $email->to);
        $this->assertSame([], $email->cc);
        $this->assertSame([], $email->bcc);
        $this->assertSame(['office@example.test'], $email->replyTo);
        $this->assertSame('no-reply@example.test', $email->from);
        $this->assertSame('[→ Ann <ann@example.test>, bob@example.test] Welcome', $email->subject);
        $this->assertSame('smtp', $sent->transport);
    }

    public function test_the_original_recipients_head_the_text_and_html(): void
    {
        $inner = new InMemoryTransport();
        (new RedirectTransport($inner, ['me@stage.test']))->send(new Email(
            to: 'ann@example.test',
            subject: 'S',
            text: 'Body',
            html: '<p>Body</p>',
            cc: 'cc@example.test',
            bcc: 'b<x>@example.test',
        ));

        $email = $inner->sent()[0]->message;
        $this->assertSame(
            "Redirected. To: ann@example.test; Cc: cc@example.test; Bcc: b<x>@example.test\n\nBody",
            $email->text,
        );
        $this->assertStringStartsWith(
            '<p style="font:12px sans-serif;color:#666">Redirected. To: ann@example.test; Cc: cc@example.test; Bcc: b&lt;x&gt;@example.test</p>',
            $email->html,
        );
        $this->assertStringEndsWith('<p>Body</p>', $email->html);
    }

    public function test_html_stays_null_when_the_email_has_none(): void
    {
        $inner = new InMemoryTransport();
        (new RedirectTransport($inner, ['me@stage.test']))->send(new Email('ann@example.test', 'S', 'T'));

        $this->assertNull($inner->sent()[0]->message->html);
    }

    public function test_other_messages_pass_through_unchanged(): void
    {
        $inner = new InMemoryTransport();
        $sms = new Sms('+41790000000', 'Hi');

        (new RedirectTransport($inner, ['me@stage.test']))->send($sms);

        $this->assertSame($sms, $inner->sent()[0]->message);
    }

    public function test_factory_wraps_a_transport_with_redirect_to(): void
    {
        $factory = new TransportFactory([
            'mail' => ['driver' => 'memory', 'redirect_to' => 'me@stage.test, you@stage.test'],
            'plain' => ['driver' => 'memory', 'redirect_to' => ''],
        ], sys_get_temp_dir());

        $mail = $factory->get('mail');
        $this->assertInstanceOf(RedirectTransport::class, $mail);
        $this->assertSame(['me@stage.test', 'you@stage.test'], $mail->recipients());
        $this->assertInstanceOf(InMemoryTransport::class, $factory->get('plain'));
    }

    public function test_redirect_to_accepts_a_list(): void
    {
        $factory = new TransportFactory([
            'mail' => ['driver' => 'memory', 'redirect_to' => ['me@stage.test']],
        ], sys_get_temp_dir());

        $this->assertSame(['me@stage.test'], $factory->get('mail')->recipients());
    }
}
