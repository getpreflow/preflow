<?php

declare(strict_types=1);

namespace Preflow\View;

interface FormHypermediaDriver
{
    /** @return array<string, string> */
    public function formAttributes(string $action, string $method, array $options = []): array;

    /** @return array<string, string> */
    public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array;

    /** @return array<string, string> */
    public function submitAttributes(string $target, array $options = []): array;
}
