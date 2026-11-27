<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Folio\Content\TypeActions;
use Preflow\Rbac\TypePermissions;

/**
 * Folio keeps its own verb map and type permission declaration (it does not require
 * preflow/rbac); with rbac installed it declares through rbac's TypePermissions. The two maps
 * and declarations are the same, so they cannot drift apart.
 */
final class TypeActionsVerbsTest extends TestCase
{
    public function test_folio_verbs_are_rbacs(): void
    {
        $this->assertTrue(class_exists(TypePermissions::class), 'rbac is installed in this suite');
        $this->assertSame(TypePermissions::VERBS, TypeActions::VERBS);
    }

    public function test_folio_declares_type_permissions_as_rbac_does(): void
    {
        $folio = new PermissionRegistry();
        TypeActions::declare($folio, 'job', 'Job');
        TypeActions::declare($folio, 'job', 'Jobs');
        $rbac = new PermissionRegistry();
        TypePermissions::declare($rbac, 'job', 'Jobs');

        $this->assertSame($rbac->all(), $folio->all());
        $this->assertSame(['job.*'], $folio->patterns(), 'the pattern once');
    }
}
