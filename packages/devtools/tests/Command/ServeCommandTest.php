<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\ServeCommand;

final class ServeCommandTest extends TestCase
{
    public function test_paths_with_spaces_are_quoted(): void
    {
        $cmd = (new ServeCommand())->buildCommand('localhost', 8080, '/tmp/my app/public', '/x/router.php');

        $this->assertStringContainsString("'/tmp/my app/public'", $cmd);
        $this->assertStringContainsString("'localhost:8080'", $cmd);
        $this->assertStringContainsString("'/x/router.php'", $cmd);
    }
}
