<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelMetadata;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormBuilder;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\Validation\ErrorBag;
use Preflow\Validation\ValidationResult;

final class FormBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        ModelMetadata::clearCache();
    }

    private function createBuilder(array $options = []): FormBuilder
    {
        return new FormBuilder(
            fieldRenderer: new FieldRenderer(),
            groupRenderer: new GroupRenderer(),
            introspector: new ModelIntrospector(),
            options: $options,
        );
    }

    public function test_begin_renders_form_tag(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->begin();
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('method="post"', $html);
    }

    public function test_begin_with_action(): void
    {
        $builder = $this->createBuilder(['action' => '/contact', 'method' => 'post']);
        $html = $builder->begin();
        $this->assertStringContainsString('action="/contact"', $html);
    }

    public function test_begin_includes_csrf_token(): void
    {
        $builder = $this->createBuilder(['csrf_token' => 'test-token-123']);
        $html = $builder->begin();
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="test-token-123"', $html);
    }

    public function test_end_renders_closing_tag(): void
    {
        $builder = $this->createBuilder();
        $this->assertSame('</form>', $builder->end());
    }

    public function test_field_renders_field_block(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->field('email', ['type' => 'email']);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('name="email"', $html);
    }

    public function test_field_with_model_binds_value(): void
    {
        $model = new TestFormModel();
        $model->email = 'test@example.com';
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->field('email');
        $this->assertStringContainsString('value="test@example.com"', $html);
    }

    public function test_field_with_model_infers_type(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->field('email');
        $this->assertStringContainsString('type="email"', $html);
    }

    public function test_field_with_model_detects_required(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->field('name');
        $this->assertStringContainsString('form-required', $html);
    }

    public function test_field_with_error_bag(): void
    {
        $result = new ValidationResult(['email' => ['Invalid email']]);
        $errorBag = new ErrorBag($result);
        $builder = $this->createBuilder(['errorBag' => $errorBag]);
        $html = $builder->field('email');
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Invalid email', $html);
    }

    public function test_old_input_overrides_model_value(): void
    {
        $model = new TestFormModel();
        $model->email = 'old@example.com';
        $builder = $this->createBuilder([
            'model' => $model,
            'oldInput' => ['email' => 'submitted@example.com'],
        ]);
        $html = $builder->field('email');
        $this->assertStringContainsString('value="submitted@example.com"', $html);
    }

    public function test_select(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->select('role', [
            'options' => ['admin' => 'Admin', 'editor' => 'Editor'],
        ]);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('Admin', $html);
    }

    public function test_checkbox(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->checkbox('active');
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_hidden(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->hidden('id', '42');
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="42"', $html);
        $this->assertStringNotContainsString('<label', $html);
    }

    public function test_submit(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->submit('Save');
        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('Save', $html);
    }

    public function test_file(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->file('avatar');
        $this->assertStringContainsString('type="file"', $html);
    }

    public function test_group(): void
    {
        $builder = $this->createBuilder();
        $builder->group(['class' => 'form-row']);
        $builder->field('zip', ['width' => '1/3']);
        $builder->field('city', ['width' => '2/3']);
        $groupClose = $builder->endGroup();
        $this->assertStringContainsString('form-group-wrapper', $groupClose);
        $this->assertStringContainsString('form-row', $groupClose);
    }

    public function test_fields_auto_generates_from_model(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->fields();
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function test_fields_with_only(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->fields(['only' => ['email', 'name']]);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function test_fields_with_except(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->fields(['except' => ['password', 'age', 'website', 'role', 'active']]);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function test_scenario_affects_required(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model, 'scenario' => 'update']);
        $html = $builder->field('password');
        $this->assertStringNotContainsString('form-required', $html);
    }

    public function test_form_level_rules_override(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder([
            'model' => $model,
            'rules' => ['password' => ['nullable', 'min:8']],
        ]);
        $html = $builder->field('password');
        $this->assertStringNotContainsString('form-required', $html);
    }

    public function test_attrs_passed_through(): void
    {
        $builder = $this->createBuilder();
        $html = $builder->field('search', [
            'attrs' => ['hx-get' => '/search', 'hx-trigger' => 'keyup'],
        ]);
        $this->assertStringContainsString('hx-get="/search"', $html);
        $this->assertStringContainsString('hx-trigger="keyup"', $html);
    }

    public function test_begin_with_custom_attrs(): void
    {
        $builder = $this->createBuilder([
            'action' => '/save',
            'attrs' => ['hx-boost' => 'true'],
        ]);
        $html = $builder->begin();
        $this->assertStringContainsString('hx-boost="true"', $html);
    }
}
