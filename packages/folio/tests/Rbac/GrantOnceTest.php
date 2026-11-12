<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Data\DynamicRecord;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;

/** RoleStore::ensure() grants a seed's permissions once: what an editor removed stays removed. */
final class GrantOnceTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function store(): RoleStore
    {
        return new RoleStore($this->container()->get(DataManager::class));
    }

    private function record(string $population, string $name): DynamicRecord
    {
        foreach ($this->roles() as $role) {
            if ($role->get('population') === $population && $role->get('name') === $name) {
                return $role;
            }
        }
        $this->fail("No role $population/$name");
    }

    /** @return list<string> */
    private function permissions(string $population, string $name): array
    {
        return RoleList::normalize($this->record($population, $name)->get('permissions'));
    }

    /** @return list<string> */
    private function seeded(string $population, string $name): array
    {
        return RoleList::normalize($this->record($population, $name)->get(RoleStore::FIELD));
    }

    /** @param list<string> $permissions */
    private function editorSets(string $name, array $permissions): void
    {
        $role = $this->record('staff', $name);
        $role->set('permissions', $permissions);
        $this->container()->get(DataManager::class)->saveType($role, false);
    }

    #[RunInSeparateProcess]
    public function test_a_new_role_records_what_it_granted(): void
    {
        $this->store()->ensure('staff', 'editor', 'Editor', ['page.*']);

        $this->assertSame(['page.*'], $this->permissions('staff', 'editor'));
        $this->assertSame(['page.*'], $this->seeded('staff', 'editor'));
    }

    #[RunInSeparateProcess]
    public function test_a_permission_added_to_the_seed_reaches_the_role_once(): void
    {
        $store = $this->store();
        $store->ensure('staff', 'editor', 'Editor', ['page.*']);
        $store->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*']);

        $this->assertSame(['page.*', 'assets.*'], $this->permissions('staff', 'editor'));
        $this->assertSame(['page.*', 'assets.*'], $this->seeded('staff', 'editor'));
    }

    #[RunInSeparateProcess]
    public function test_a_permission_an_editor_removed_stays_removed(): void
    {
        $store = $this->store();
        $store->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*']);
        $this->editorSets('editor', ['page.*']);
        $store->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*']);

        $this->assertSame(['page.*'], $this->permissions('staff', 'editor'));
        $this->assertSame(['page.*', 'assets.*'], $this->seeded('staff', 'editor'));
    }

    #[RunInSeparateProcess]
    public function test_a_permission_an_editor_added_stays(): void
    {
        $store = $this->store();
        $store->ensure('staff', 'editor', 'Editor', ['page.*']);
        $this->editorSets('editor', ['page.*', 'ai.translate']);
        $store->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*']);

        $this->assertSame(['page.*', 'ai.translate', 'assets.*'], $this->permissions('staff', 'editor'));
    }

    #[RunInSeparateProcess]
    public function test_first_sight_of_a_role_without_a_record_adds_nothing(): void
    {
        $this->role('r-ed', 'staff', 'editor', ['page.*']);
        $store = $this->store();
        $store->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*']);

        $this->assertSame(['page.*'], $this->permissions('staff', 'editor'));
        $this->assertSame(['page.*', 'assets.*'], $this->seeded('staff', 'editor'));

        $store->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*', 'members.*']);

        $this->assertSame(['page.*', 'members.*'], $this->permissions('staff', 'editor'));
    }

    #[RunInSeparateProcess]
    public function test_a_json_string_record_is_read(): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('rbac_role'), [
            'uuid' => 'r-ed', 'name' => 'editor', 'label' => 'Editor', 'population' => 'staff',
            'permissions' => ['page.*'], RoleStore::FIELD => '["page.*"]',
        ]), false);
        $this->store()->ensure('staff', 'editor', 'Editor', ['page.*', 'assets.*']);

        $this->assertSame(['page.*', 'assets.*'], $this->permissions('staff', 'editor'));
    }

    #[RunInSeparateProcess]
    public function test_roles_of_two_populations_are_separate(): void
    {
        $store = $this->store();
        $store->ensure('staff', 'admin', 'Administrator', ['*']);
        $store->ensure('members', 'admin', 'Administrator', ['members.access']);
        $store->ensure('staff', 'admin', 'Administrator', ['*']);
        $store->ensure('members', 'admin', 'Administrator', ['members.access']);

        $this->assertSame(['*'], $this->permissions('staff', 'admin'));
        $this->assertSame(['*'], $this->seeded('staff', 'admin'));
        $this->assertSame(['members.access'], $this->permissions('members', 'admin'));
        $this->assertSame(['members.access'], $this->seeded('members', 'admin'));
    }

    /**
     * A site that added the field itself before it was built in (holz-objekte.org) must delete
     * its extends file when it upgrades: Folio refuses a field the extension already defines.
     */
    #[RunInSeparateProcess]
    public function test_a_site_extends_file_for_the_same_field_must_be_removed(): void
    {
        $this->write('config/models/rbac_role.json', (string) json_encode([
            'key' => 'rbac_role', 'extends' => true,
            'fields' => [RoleStore::FIELD => ['type' => 'text', 'hidden' => true]],
        ]));

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('field "seeded_permissions" is already defined by the extension');
        $this->container();
    }
}
