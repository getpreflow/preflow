<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Rbac\Fields\RolesFieldType;

/** The roles field's error state: the group is marked (admin.css turns every chip's border red) and every message shows. */
final class RolesFieldTypeTest extends TestCase
{
    public function test_a_failed_roles_field_marks_the_group_and_lists_every_error(): void
    {
        // No population (unknown type, no config): the stored roles still show as chips, no role records are read.
        $field = new RolesFieldType(new DataManager([]), new TypeCatalog('/no/such/models'));
        $html = $field->renderEditor(new FieldContext(
            name: 'roles',
            label: 'Roles',
            value: ['editor'],
            errors: ['Pick at least one role.', 'Editor needs a second role.'],
        ));

        $this->assertStringContainsString('<fieldset class="form-group rbac-roles has-error"><legend>Roles</legend>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="roles[]" value="editor" checked>', $html);
        $this->assertStringContainsString('<div class="form-error">Pick at least one role.</div>', $html);
        $this->assertStringContainsString('<div class="form-error">Editor needs a second role.</div>', $html);
    }

    public function test_a_valid_roles_field_has_no_error_state(): void
    {
        $field = new RolesFieldType(new DataManager([]), new TypeCatalog('/no/such/models'));
        $html = $field->renderEditor(new FieldContext(name: 'roles', label: 'Roles', value: ['editor']));

        $this->assertStringContainsString('<fieldset class="form-group rbac-roles"><legend>Roles</legend>', $html);
        $this->assertStringNotContainsString('form-error', $html);
    }
}
