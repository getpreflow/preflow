<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/** "required" binds the default locale only; other rules apply to every locale present. */
final class LocalizedValidationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lv_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'slug' => ['type' => 'string', 'translatable' => true, 'validate' => ['regex:/^[a-z-]+$/']],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/store/page/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/store/page');
        @rmdir($this->dir . '/store');
        @unlink($this->dir . '/models/page.json');
        @rmdir($this->dir . '/models');
        @rmdir($this->dir);
    }

    public function test_required_applies_to_default_locale_only(): void
    {
        $this->assertSame(['title'], array_keys($this->errors(['title' => ['de' => 'X']])));
        $this->assertSame([], $this->errors(['title' => ['en' => 'E']]));
    }

    public function test_other_rules_apply_to_every_present_locale(): void
    {
        $errors = $this->errors(['title' => ['en' => 'E'], 'slug' => ['en' => 'ok', 'de' => 'Nicht OK']]);

        $this->assertSame(['slug.de'], array_keys($errors));
    }

    public function test_empty_other_locale_is_not_validated(): void
    {
        $this->assertSame([], $this->errors(['title' => ['en' => 'E', 'de' => ''], 'slug' => ['en' => 'ok', 'de' => '']]));
    }

    public function test_single_locale_validation_unchanged(): void
    {
        $this->assertSame(['title'], array_keys($this->errors(['title' => ''], Locales::single('en'))));
        $this->assertSame([], $this->errors(['title' => 'T', 'slug' => 'ok'], Locales::single('en')));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, list<string>>
     */
    private function errors(array $data, ?Locales $locales = null): array
    {
        $registry = new TypeRegistry($this->dir . '/models', $locales ?? new Locales(['en', 'de'], 'en'));
        $dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        try {
            $dm->saveType(DynamicRecord::fromArray($registry->get('page'), ['uuid' => 'p1'] + $data));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
