<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\View\FormHypermediaDriver;

/** LazyHypermediaDriver for a driver that also writes form attributes (FormHypermediaDriver). */
final class LazyFormHypermediaDriver extends LazyHypermediaDriver implements FormHypermediaDriver
{
    public function formAttributes(string $action, string $method, array $options = []): array
    {
        return $this->form()->formAttributes($action, $method, $options);
    }

    public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array
    {
        return $this->form()->inlineValidationAttributes($endpoint, $field, $trigger);
    }

    public function submitAttributes(string $target, array $options = []): array
    {
        return $this->form()->submitAttributes($target, $options);
    }

    private function form(): FormHypermediaDriver
    {
        $driver = $this->driver();
        assert($driver instanceof FormHypermediaDriver);

        return $driver;
    }
}
