<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\RequestContext;

final class RequestContextTest extends TestCase
{
    public function test_stores_path(): void
    {
        $context = new RequestContext(path: '/blog/my-post', method: 'GET');

        $this->assertSame('/blog/my-post', $context->path);
    }

    public function test_stores_method(): void
    {
        $context = new RequestContext(path: '/', method: 'POST');

        $this->assertSame('POST', $context->method);
    }

    public function test_is_readonly(): void
    {
        $reflection = new \ReflectionClass(RequestContext::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
