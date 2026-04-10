<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

interface CommandInterface
{
    public function getName(): string;
    public function getDescription(): string;
    /** @param string[] $args */
    public function execute(array $args): int;
}
