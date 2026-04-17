<?php

declare(strict_types=1);

namespace Preflow\Validation\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Validate
{
    /** @var list<string> */
    public readonly array $rules;
    public readonly ?string $on;

    public function __construct(string ...$rules)
    {
        $on = null;
        $filtered = [];
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'on:')) {
                $on = substr($rule, 3);
            } else {
                $filtered[] = $rule;
            }
        }
        $this->rules = $filtered;
        $this->on = $on;
    }
}
