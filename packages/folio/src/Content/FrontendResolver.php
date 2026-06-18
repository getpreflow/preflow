<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

final class FrontendResolver
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly string $type = 'page',
    ) {}

    public function resolve(string $path): ?DynamicRecord
    {
        $slug = trim($path, '/');
        if ($slug === '') {
            $slug = 'home';
        }

        return $this->dm->queryType($this->type)
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();
    }
}
