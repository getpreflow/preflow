<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Fixtures;

use Preflow\DevTools\Command\CommandInterface;

final class HelloCommand implements CommandInterface
{
    public function getName(): string { return 'fixture:hello'; }
    public function getDescription(): string { return 'Say hello'; }

    public function execute(array $args): int
    {
        echo "hello\n";
        return 0;
    }
}
