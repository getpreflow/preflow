<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function test_package_namespace_autoloads(): void
    {
        $this->assertTrue(class_exists(\Preflow\Folio\Tests\AutoloadTest::class));
    }
}
