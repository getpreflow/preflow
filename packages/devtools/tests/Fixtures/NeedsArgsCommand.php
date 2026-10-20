<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Fixtures;

use Preflow\DevTools\Command\CommandInterface;

final class NeedsArgsCommand implements CommandInterface
{
    public function __construct(private readonly string $required) {}

    public function getName(): string { return 'fixture:needs-args'; }
    public function getDescription(): string { return $this->required; }
    public function execute(array $args): int { return 0; }
}
