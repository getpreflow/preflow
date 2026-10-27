<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

final class NumericLengthTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private \Preflow\Data\TypeDefinition $def;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nl_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json',
            'fields' => [
                'slug' => ['type' => 'string', 'unique' => true],
                'code' => ['type' => 'string', 'index' => true, 'length' => 10],
            ],
        ]));
        $registry = new TypeRegistry($this->dir, Locales::single('en'));
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $this->def = $registry->get('page');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_number_like_slugs_save(): void
    {
        foreach (['2024', '500', '1830'] as $i => $slug) {
            $this->dm->saveType(DynamicRecord::fromArray($this->def, ['uuid' => 'u' . $i, 'slug' => $slug]));
        }
        $this->assertCount(3, $this->dm->queryType('page')->get()->items());
    }

    public function test_overlong_digits_are_refused_as_text(): void
    {
        $this->assertSame(['slug' => (new Message('validation.max.string', ['max' => '191']))->english()], $this->failures(['uuid' => 'a', 'slug' => str_repeat('7', 192)]));
        $this->assertSame(['code' => (new Message('validation.max.string', ['max' => '10']))->english()], $this->failures(['uuid' => 'b', 'slug' => 'ok', 'code' => '0000000000000']));
    }

    /** @return array<string, string> */
    private function failures(array $data): array
    {
        try {
            $this->dm->saveType(DynamicRecord::fromArray($this->def, $data));
        } catch (ValidationException $e) {
            $out = [];
            foreach ($e->errors() as $field => $messages) {
                $out[$field] = $messages[0];
            }
            return $out;
        }
        return [];
    }
}
