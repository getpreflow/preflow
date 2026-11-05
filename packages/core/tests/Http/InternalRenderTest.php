<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\InternalRender;

final class InternalRenderTest extends TestCase
{
    public function test_a_plain_request_is_not_an_internal_render(): void
    {
        $this->assertFalse(InternalRender::is(new ServerRequest('GET', '/')));
    }

    public function test_mark_returns_a_marked_copy(): void
    {
        $request = new ServerRequest('GET', '/');

        $marked = InternalRender::mark($request);

        $this->assertTrue(InternalRender::is($marked));
        $this->assertTrue($marked->getAttribute('preflow.internal_render'));
        $this->assertFalse(InternalRender::is($request)); // requests are immutable
        $this->assertSame('preflow.internal_render', InternalRender::ATTRIBUTE);
    }

    public function test_only_true_marks_a_request(): void
    {
        foreach (['1', 1, 'yes', false, null] as $value) {
            $this->assertFalse(InternalRender::is((new ServerRequest('GET', '/'))->withAttribute(InternalRender::ATTRIBUTE, $value)));
        }
    }
}
