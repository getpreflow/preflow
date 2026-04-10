<?php
declare(strict_types=1);
namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Post extends HttpMethod
{
    public function __construct(string $path = '/')
    {
        parent::__construct($path, 'POST');
    }
}
