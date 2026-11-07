<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Emitter;
use Psr\Http\Message\ServerRequestInterface;

final class EmitterTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** @return array{0: list<string>, 1: string} */
    private function emit(Response $response, ?ServerRequestInterface $request = null): array
    {
        $this->lines = [];
        $emitter = new Emitter(function (string $line): void {
            $this->lines[] = $line;
        });
        if (headers_sent()) {
            $this->markTestSkipped('Output already started.');
        }
        ob_start();
        $emitter->emit($response, $request);
        $out = (string) ob_get_clean();

        return [$this->lines, $out];
    }

    private function req(string $method = 'GET', string $version = '1.1'): ServerRequest
    {
        return new ServerRequest($method, '/', [], null, $version);
    }

    public function test_known_size_body_gets_content_length(): void
    {
        [$lines, $out] = $this->emit(new Response(200, [], 'hello'), $this->req());
        $this->assertContains('Content-Length: 5', $lines);
        $this->assertSame('hello', $out);
    }

    public function test_http10_request_gets_http10_status_line_and_no_chunked(): void
    {
        [$lines] = $this->emit(new Response(200, [], 'hello'), $this->req('GET', '1.0'));
        $this->assertSame('HTTP/1.0 200 OK', $lines[0]);
        $this->assertContains('Content-Length: 5', $lines);
        $this->assertNotContains('Transfer-Encoding: chunked', $lines);
    }

    public function test_head_keeps_the_get_length_and_sends_no_body(): void
    {
        [$lines, $out] = $this->emit(new Response(200, [], 'hello'), $this->req('HEAD'));
        $this->assertContains('Content-Length: 5', $lines);
        $this->assertSame('', $out);

        // Application strips the HEAD body but keeps the GET length.
        $stripped = new Response(200, ['Content-Length' => '5'], '');
        [$lines, $out] = $this->emit($stripped, $this->req('HEAD'));
        $this->assertContains('Content-Length: 5', $lines);
        $this->assertSame('', $out);
    }

    public function test_204_and_304_send_no_length_and_no_body(): void
    {
        foreach ([204, 304] as $status) {
            [$lines, $out] = $this->emit(new Response($status, [], 'x'), $this->req());
            $this->assertNotContains('Content-Length: 1', $lines);
            $this->assertContains('!Content-Length', $lines);
            $this->assertSame('', $out);
        }
    }

    public function test_streamed_body_gets_no_content_length(): void
    {
        $stream = new class ('abc') implements \Psr\Http\Message\StreamInterface {
            private int $pos = 0;
            public function __construct(private string $d) {}
            public function __toString(): string { return $this->d; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return $this->pos; }
            public function eof(): bool { return $this->pos >= strlen($this->d); }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write(string $string): int { return 0; }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { $r = substr($this->d, $this->pos, $length); $this->pos += strlen($r); return $r; }
            public function getContents(): string { return $this->read(PHP_INT_MAX); }
            public function getMetadata(?string $key = null) { return null; }
        };
        foreach (['1.1', '1.0'] as $v) {
            $stream2 = clone $stream;
            [$lines, $out] = $this->emit((new Response(200))->withBody($stream2), $this->req('GET', $v));
            $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'Content-Length'))));
            $this->assertSame('abc', $out);
            $this->assertSame($v === '1.0', in_array('Connection: close', $lines, true));
        }
    }

    public function test_stray_buffered_output_suppresses_content_length(): void
    {
        ob_start();
        echo 'x'; // a notice, a BOM or trailing whitespace already in the output buffer
        try {
            [$lines, $out] = $this->emit(new Response(200, [], 'hello'), $this->req());
        } finally {
            ob_end_clean();
        }
        $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'Content-Length'))));
        $this->assertSame('hello', $out);
    }

    public function test_output_handler_suppresses_content_length(): void
    {
        ob_start(static fn (string $buffer): string => $buffer); // e.g. compression rewrites the body
        try {
            [$lines] = $this->emit(new Response(200, [], 'hello'), $this->req());
        } finally {
            ob_end_clean();
        }
        $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'Content-Length'))));
    }

    public function test_explicit_content_length_header_is_kept(): void
    {
        ob_start();
        echo 'x';
        try {
            [$lines] = $this->emit(new Response(200, ['Content-Length' => '5'], 'hello'), $this->req());
        } finally {
            ob_end_clean();
        }
        $this->assertContains('Content-Length: 5', $lines);
    }

    public function test_status_line_protocol_is_clamped_to_10_or_11(): void
    {
        foreach (['2' => '1.1', '3.0' => '1.1', '0.9' => '1.1', '1.0' => '1.0', '1.1' => '1.1'] as $in => $want) {
            [$lines] = $this->emit(new Response(200, [], 'hi'), $this->req('GET', (string) $in));
            $this->assertSame("HTTP/{$want} 200 OK", $lines[0], "protocol {$in}");
        }
    }
}
