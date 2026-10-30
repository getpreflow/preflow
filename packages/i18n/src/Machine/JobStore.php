<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

interface JobStore
{
    public function save(Job $job): void;

    public function get(string $id): ?Job;
}
