<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Access;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\PermissionRegistry;

final class PermissionRegistryTest extends TestCase
{
    public function test_declared_names_and_patterns(): void
    {
        $r = new PermissionRegistry();
        $r->declare('folio.dashboard', 'Dashboard', 'Folio');
        $r->declarePattern('page.*');

        $this->assertTrue($r->isDeclared('folio.dashboard'));
        $this->assertTrue($r->isDeclared('page.publish'));
        $this->assertFalse($r->isDeclared('pages.publish'));
        $this->assertFalse($r->isDeclared('site.edit'));
        $this->assertSame(['label' => 'Dashboard', 'group' => 'Folio'], $r->all()['folio.dashboard']);
    }
}
