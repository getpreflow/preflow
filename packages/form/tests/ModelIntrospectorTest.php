<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Form\ModelIntrospector;
use Preflow\Validation\Attributes\Validate;

#[Entity('test_users')]
class TestFormModel extends Model
{
    #[Id]
    public int $id = 0;

    #[Validate('required', 'email')]
    public string $email = '';

    #[Validate('required', 'min:8', 'on:create')]
    #[Validate('nullable', 'min:8', 'on:update')]
    public string $password = '';

    #[Validate('required')]
    public string $name = '';

    #[Validate('integer')]
    public int $age = 0;

    #[Validate('url')]
    public string $website = '';

    #[Validate('in:admin,editor,viewer')]
    public string $role = 'viewer';

    public bool $active = true;
}

final class ModelIntrospectorTest extends TestCase
{
    private ModelIntrospector $introspector;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $this->introspector = new ModelIntrospector();
    }

    public function test_get_fields_returns_validated_properties(): void
    {
        $fields = $this->introspector->getFields(TestFormModel::class);
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayHasKey('password', $fields);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayNotHasKey('id', $fields);
    }

    public function test_infer_type_from_email_rule(): void
    {
        $this->assertSame('email', $this->introspector->inferType('email', TestFormModel::class));
    }

    public function test_infer_type_from_url_rule(): void
    {
        $this->assertSame('url', $this->introspector->inferType('website', TestFormModel::class));
    }

    public function test_infer_type_from_integer_rule(): void
    {
        $this->assertSame('number', $this->introspector->inferType('age', TestFormModel::class));
    }

    public function test_infer_type_from_in_rule(): void
    {
        $this->assertSame('select', $this->introspector->inferType('role', TestFormModel::class));
    }

    public function test_infer_type_from_bool_property(): void
    {
        $this->assertSame('checkbox', $this->introspector->inferType('active', TestFormModel::class));
    }

    public function test_is_required(): void
    {
        $this->assertTrue($this->introspector->isRequired('email', TestFormModel::class));
        $this->assertTrue($this->introspector->isRequired('name', TestFormModel::class));
    }

    public function test_is_required_with_scenario(): void
    {
        $this->assertTrue($this->introspector->isRequired('password', TestFormModel::class, 'create'));
        $this->assertFalse($this->introspector->isRequired('password', TestFormModel::class, 'update'));
    }

    public function test_get_in_options(): void
    {
        $options = $this->introspector->getInOptions('role', TestFormModel::class);
        $this->assertSame(['admin', 'editor', 'viewer'], $options);
    }

    public function test_get_values_from_model(): void
    {
        $model = new TestFormModel();
        $model->email = 'test@example.com';
        $model->name = 'Alice';
        $values = $this->introspector->getValues($model);
        $this->assertSame('test@example.com', $values['email']);
        $this->assertSame('Alice', $values['name']);
    }
}
