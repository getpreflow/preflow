<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\View\FormHypermediaDriver;

/** SignalDriver with form attributes: the form helpers use it too. */
final class SignalFormDriver extends SignalDriver implements FormHypermediaDriver
{
    public function formAttributes(string $action, string $method, array $options = []): array
    {
        return ['data-on:submit' => '@' . $method . '(' . json_encode($action, JSON_UNESCAPED_SLASHES) . ')'];
    }

    public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array
    {
        return ["data-on:{$trigger}" => '@post(' . json_encode($endpoint, JSON_UNESCAPED_SLASHES) . ')'];
    }

    public function submitAttributes(string $target, array $options = []): array
    {
        return [];
    }
}
