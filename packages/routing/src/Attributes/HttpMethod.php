<?php
declare(strict_types=1);
namespace Preflow\Routing\Attributes;

abstract class HttpMethod
{
    public function __construct(
        public readonly string $path = '/',
        public readonly string $method = 'GET',
    ) {}
}
