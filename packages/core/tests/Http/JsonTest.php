<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Json;

final class JsonTest extends TestCase
{
    public function test_response_encodes_with_status_headers_and_content_type(): void
    {
        $response = Json::response(['a' => 'é/x', 'n' => 1.0], 201, ['X-Custom' => 'yes']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('yes', $response->getHeaderLine('X-Custom'));
        $this->assertSame('{"a":"é/x","n":1.0}', (string) $response->getBody());
    }

    public function test_data_wraps_in_envelope_and_adds_meta_only_when_given(): void
    {
        $this->assertSame('{"data":[1]}', (string) Json::data([1])->getBody());
        $this->assertSame(
            '{"data":[1],"meta":{"page":2}}',
            (string) Json::data([1], ['page' => 2])->getBody(),
        );
    }

    public function test_no_content_is_204_without_body(): void
    {
        $response = Json::noContent();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function test_unencodable_body_throws_json_exception(): void
    {
        $this->expectException(\JsonException::class);

        Json::response(NAN);
    }
}
