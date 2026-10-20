<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final readonly class TypeListing
{
    /**
     * @param array<string, mixed>|null $frontend the type's "frontend" block, if it has one
     * @param array<string, mixed>      $list     the type's "list" block (admin list config)
     * @param bool                      $publishing working copy + published snapshot ("publishing"; default: frontend types and singletons)
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?array $frontend = null,
        public array $list = [],
        public bool $singleton = false,
        public bool $publishing = false,
    ) {}
}
