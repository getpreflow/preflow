<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Validation\Attributes\Validate;

#[Entity(table: 'scenario_users', storage: 'default')]
class ScenarioUser extends Model
{
    #[Id]
    public int $id = 0;

    // Always required (no scenario)
    #[Field]
    #[Validate('required', 'min:2')]
    public string $name = '';

    // Required only on create
    #[Field]
    #[Validate('required', 'email', 'on:create')]
    public string $email = '';

    // Required only on update
    #[Field]
    #[Validate('required', 'on:update')]
    public ?string $bio = null;
}

final class ModelMetadataScenarioTest extends TestCase
{
    protected function setUp(): void
    {
        ModelMetadata::clearCache();
    }

    public function test_rules_without_on_apply_to_all_scenarios(): void
    {
        $meta = ModelMetadata::for(ScenarioUser::class);

        $createRules = $meta->validationRulesForScenario('create');
        $updateRules = $meta->validationRulesForScenario('update');
        $nullRules   = $meta->validationRulesForScenario(null);

        $this->assertArrayHasKey('name', $createRules);
        $this->assertArrayHasKey('name', $updateRules);
        $this->assertArrayHasKey('name', $nullRules);
    }

    public function test_on_create_rules_only_apply_for_create_scenario(): void
    {
        $meta = ModelMetadata::for(ScenarioUser::class);

        $createRules = $meta->validationRulesForScenario('create');
        $updateRules = $meta->validationRulesForScenario('update');

        $this->assertArrayHasKey('email', $createRules);
        $this->assertNotEmpty($createRules['email']);

        // email has no rules that match 'update' scenario, so should be absent or empty
        $this->assertArrayNotHasKey('email', $updateRules);
    }

    public function test_on_update_rules_only_apply_for_update_scenario(): void
    {
        $meta = ModelMetadata::for(ScenarioUser::class);

        $updateRules = $meta->validationRulesForScenario('update');
        $createRules = $meta->validationRulesForScenario('create');

        $this->assertArrayHasKey('bio', $updateRules);
        $this->assertNotEmpty($updateRules['bio']);

        $this->assertArrayNotHasKey('bio', $createRules);
    }

    public function test_null_scenario_returns_only_global_rules(): void
    {
        $meta = ModelMetadata::for(ScenarioUser::class);

        $rules = $meta->validationRulesForScenario(null);

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayNotHasKey('email', $rules);
        $this->assertArrayNotHasKey('bio', $rules);
    }

    public function test_validation_rules_property_unchanged_for_backward_compat(): void
    {
        $meta = ModelMetadata::for(ScenarioUser::class);

        // The existing validationRules property should still contain all rules
        // (merged, regardless of scenario) for backward compatibility
        $this->assertArrayHasKey('name', $meta->validationRules);
    }
}
