<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\FieldKinds;

final class FieldKindsTest extends TestCase
{
    public function test_defaults(): void
    {
        $kinds = new FieldKinds();

        $this->assertTrue($kinds->isText('string'));
        $this->assertTrue($kinds->isText('text'));
        $this->assertFalse($kinds->isText('integer'));
        $this->assertTrue($kinds->isRelation('relation'));
        $this->assertFalse($kinds->isRelation('string'));
    }

    public function test_registration(): void
    {
        $kinds = new FieldKinds();
        $kinds->text('markdown', 'slug');
        $kinds->relation('pick');

        $this->assertTrue($kinds->isText('markdown'));
        $this->assertTrue($kinds->isText('slug'));
        $this->assertTrue($kinds->isRelation('pick'));
        $this->assertTrue($kinds->isText('string'));
    }

    public function test_data_manager_shares_one_instance(): void
    {
        $dm = new DataManager([]);

        $this->assertSame($dm->fieldKinds(), $dm->fieldKinds());
    }
}
