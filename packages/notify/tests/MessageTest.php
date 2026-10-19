<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Attachment;
use Preflow\Notify\Email;
use Preflow\Notify\Sms;

final class MessageTest extends TestCase
{
    public function test_email_normalizes_string_recipients_to_lists(): void
    {
        $email = new Email('a@b.test', 'S', 'T');

        $this->assertSame(['a@b.test'], $email->to);
        $this->assertSame([], $email->cc);
        $this->assertSame([], $email->bcc);
        $this->assertSame([], $email->replyTo);
    }

    public function test_email_requires_a_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Email([], 'S', 'T');
    }

    public function test_rejects_header_injection_in_subject_and_addresses(): void
    {
        try {
            new Email('a@b.test', "Hi\r\nBcc: x@y.z", 'T');
            $this->fail('Subject with CRLF must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new Email('a@b.test', 'Hi', 'T', replyTo: "a@b.test\nBcc: x@y.z");
    }

    public function test_sms_requires_e164(): void
    {
        $sms = new Sms('+41790000000', 'T');
        $this->assertSame('+41790000000', $sms->to);

        $this->expectException(\InvalidArgumentException::class);
        new Sms('0790000000', 'T');
    }

    public function test_with_from_returns_copy(): void
    {
        $email = new Email('a@b.test', 'S', 'T');
        $withFrom = $email->withFrom('me@x.test');

        $this->assertSame('me@x.test', $withFrom->from);
        $this->assertNull($email->from);

        $sms = (new Sms('+41790000000', 'T'))->withFrom('Preflow');
        $this->assertSame('Preflow', $sms->from);
    }

    public function test_channels(): void
    {
        $this->assertSame('email', (new Email('a@b.test', 'S', 'T'))->channel());
        $this->assertSame('sms', (new Sms('+41790000000', 'T'))->channel());
    }

    public function test_attachment_factories(): void
    {
        $fromPath = Attachment::fromPath('/tmp/report.pdf');
        $this->assertSame('/tmp/report.pdf', $fromPath->path);
        $this->assertSame('report.pdf', $fromPath->name);
        $this->assertNull($fromPath->contents);

        $fromContents = Attachment::fromContents('hello', 'note.txt', 'text/plain');
        $this->assertSame('hello', $fromContents->contents);
        $this->assertSame('note.txt', $fromContents->name);
        $this->assertSame('text/plain', $fromContents->mime);
        $this->assertNull($fromContents->path);
    }
}
