<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

abstract class RouteTestCase extends TestCase
{
    protected function createTestResponse(ResponseInterface $response): TestResponse
    {
        return new TestResponse($response);
    }
}
