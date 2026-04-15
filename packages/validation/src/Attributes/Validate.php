<?php

declare(strict_types=1);

namespace Preflow\Validation\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Validate
{
    /** @var list<string> */
    public readonly array $rules;

    public function __construct(string ...$rules)
    {
        $this->rules = $rules;
    }
}
