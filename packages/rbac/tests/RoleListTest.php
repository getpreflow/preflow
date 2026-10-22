<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Rbac\RoleList;

final class RoleListTest extends TestCase
{
    public function test_normalize_accepts_array_json_and_csv(): void
    {
        $this->assertSame(['editor', 'admin'], RoleList::normalize(['editor', ' admin ', '', 'editor']));
        $this->assertSame(['editor', 'admin'], RoleList::normalize('["editor","admin"]'));
        $this->assertSame(['editor', 'admin'], RoleList::normalize('editor, admin,,editor'));
        $this->assertSame(['member'], RoleList::normalize('member'));
        $this->assertSame([], RoleList::normalize(null));
        $this->assertSame([], RoleList::normalize(''));
        $this->assertSame([], RoleList::normalize('[]'));
        // A JSON string inside an array (a record field cast with (array)) is unpacked.
        $this->assertSame(['editor', 'admin'], RoleList::normalize(['["editor"]', 'admin']));
        // Malformed entries are dropped, never granted.
        $this->assertSame(['7', 'a'], RoleList::normalize([7, ['x'], null, true, 'a']));
    }
}
