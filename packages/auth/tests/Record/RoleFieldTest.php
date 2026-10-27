<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Record;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Record\RecordUser;
use Preflow\Auth\Record\RoleField;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** A stored roles field, however it arrives: the one rule RecordUser (and the members core) read it by. */
final class RoleFieldTest extends TestCase
{
    /** @return array<string, array{mixed, list<string>}> */
    public static function values(): array
    {
        return [
            'list' => [['member', ' vip ', 'member', ''], ['member', 'vip']],
            'JSON (as SQL returns it)' => ['["member","vip"]', ['member', 'vip']],
            'comma-separated' => ['member, vip', ['member', 'vip']],
            'a JSON string inside a list' => [['["member"]', 'vip'], ['member', 'vip']],
            'an int' => [7, ['7']],
            'nothing' => [null, []],
            'malformed entries dropped' => [['member', ['x'], 1.5, true], ['member']],
            'broken JSON' => ['[member', []],
        ];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function test_normalize(mixed $value, array $expected): void
    {
        $this->assertSame($expected, RoleField::normalize($value));
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function test_a_record_user_reads_its_roles_by_the_same_rule(mixed $value, array $expected): void
    {
        $def = new TypeDefinition(key: 'member', table: 'member', storage: 'default', fields: ['roles' => new TypeFieldDefinition(name: 'roles', type: 'list')], idField: 'uuid', locales: Locales::single('en'));

        $this->assertSame($expected, (new RecordUser(DynamicRecord::fromArray($def, ['uuid' => 'm1', 'roles' => $value])))->getRoles());
    }
}
