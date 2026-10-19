<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Attachment;
use Preflow\Notify\Bridge\SymfonyMailer\SymfonyMailerTransport;
use Preflow\Notify\Email;
use Preflow\Notify\Sms;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

final class SymfonyMailerTransportTest extends TestCase
{
    public function test_maps_every_email_field(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($file, 'pdf-bytes');

        $email = new Email(
            to: ['a@b.test', 'Bea <bea@b.test>'],
            subject: 'Hello',
            text: 'Plain',
            html: '<p>Rich</p>',
            from: 'Site <site@x.test>',
            replyTo: 'visitor@y.test',
            cc: 'cc@b.test',
            bcc: ['bcc@b.test'],
            attachments: [
                Attachment::fromContents('hello', 'note.txt', 'text/plain'),
                Attachment::fromPath($file, 'report.pdf', 'application/pdf'),
            ],
        );

        $symfony = (new SymfonyMailerTransport('null://null', 'smtp'))->toSymfonyEmail($email);

        $this->assertSame(['a@b.test', 'bea@b.test'], array_map(fn ($a) => $a->getAddress(), $symfony->getTo()));
        $this->assertSame('Bea', $symfony->getTo()[1]->getName());
        $this->assertSame('site@x.test', $symfony->getFrom()[0]->getAddress());
        $this->assertSame('Site', $symfony->getFrom()[0]->getName());
        $this->assertSame('visitor@y.test', $symfony->getReplyTo()[0]->getAddress());
        $this->assertSame('cc@b.test', $symfony->getCc()[0]->getAddress());
        $this->assertSame('bcc@b.test', $symfony->getBcc()[0]->getAddress());
        $this->assertSame('Hello', $symfony->getSubject());
        $this->assertSame('Plain', $symfony->getTextBody());
        $this->assertSame('<p>Rich</p>', $symfony->getHtmlBody());
        $this->assertCount(2, $symfony->getAttachments());
        $this->assertSame(
            ['note.txt', 'report.pdf'],
            array_map(fn ($p) => $p->getFilename(), $symfony->getAttachments()),
        );

        @unlink($file);
    }

    public function test_sends_through_null_dsn(): void
    {
        $transport = new SymfonyMailerTransport('null://null', 'smtp');

        $this->assertTrue($transport->supports(new Email('a@b.test', 'S', 'T')));
        $this->assertFalse($transport->supports(new Sms('+41790000000', 'T')));

        $sent = $transport->send(new Email('a@b.test', 'S', 'T', from: 'site@x.test'));

        $this->assertSame('smtp', $sent->transport);
    }

    public function test_empty_dsn_fails_on_send_not_on_create(): void
    {
        $transport = new SymfonyMailerTransport('', 'smtp');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Transport "smtp": dsn is empty (set it in .env).');
        $transport->send(new Email('a@b.test', 'S', 'T', from: 'site@x.test'));
    }

    public function test_missing_from_is_a_clear_error(): void
    {
        $transport = new SymfonyMailerTransport('null://null', 'smtp');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Transport "smtp": email has no from address; set channels.email.from in config/notify.php.');
        $transport->send(new Email('a@b.test', 'S', 'T'));
    }

    public function test_provider_errors_are_wrapped_and_masked(): void
    {
        $transport = new SymfonyMailerTransport('smtp://user:s3cret@127.0.0.1:1', 'smtp');

        try {
            $transport->send(new Email('a@b.test', 'S', 'T', from: 'site@x.test'));
            $this->fail('Expected a TransportException');
        } catch (TransportException $e) {
            $this->assertStringStartsWith('Transport "smtp" failed:', $e->getMessage());
            $this->assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }

    public function test_factory_registers_bridges_when_installed(): void
    {
        $factory = new TransportFactory([], sys_get_temp_dir());

        $this->assertTrue($factory->hasDriver('symfony-mailer'));
        $this->assertTrue($factory->hasDriver('symfony-notifier-sms'));
    }
}
