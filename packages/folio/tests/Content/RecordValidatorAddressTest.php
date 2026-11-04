<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/** The fields a public address is built from only take lowercase a-z, digits and hyphens. */
final class RecordValidatorAddressTest extends TestCase
{
    private string $dir;
    private TypeRegistry $registry;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rva_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $types = [
            'page' => ['slug' => ['type' => 'string', 'translatable' => true], 'title' => ['type' => 'string']],
            'doc' => ['path' => ['type' => 'string'], 'title' => ['type' => 'string']],
            'event' => ['year' => ['type' => 'string'], 'slug' => ['type' => 'string']],
            'locked' => ['slug' => ['type' => 'string', 'readonly' => true]],
            'note' => ['slug' => ['type' => 'string']],
        ];
        foreach ($types as $key => $fields) {
            file_put_contents($this->dir . "/{$key}.json", json_encode(['key' => $key, 'table' => $key, 'storage' => 'json', 'fields' => $fields]));
        }
        $this->registry = new TypeRegistry($this->dir, new Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function validator(): RecordValidator
    {
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $frontend = new FrontendResolver($this->dm, [
            FrontendRoute::fromConfig('page', ['route' => '/{slug}', 'home' => 'home']),
            FrontendRoute::fromConfig('doc', ['route' => '/docs/{...path}']),
            FrontendRoute::fromConfig('event', ['route' => '/events/{year}/{slug}']),
            FrontendRoute::fromConfig('locked', ['route' => '/locked/{slug}']),
        ]);

        return new RecordValidator($fieldTypes, $this->dm, $frontend);
    }

    /** @param array<string, mixed> $data @return array<string, list<string>> */
    private function errors(string $type, array $data): array
    {
        try {
            $this->validator()->validate(DynamicRecord::fromArray($this->registry->get($type), ['uuid' => 'u1', ...$data]));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    private static function segment(): string
    {
        return (new Message('validation.slug.segment'))->english();
    }

    public function test_clean_addresses_pass_in_every_language(): void
    {
        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'about-us', 'de' => 'ueber-uns'], 'title' => 'Über uns']));
        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'home']]));
        $this->assertSame([], $this->errors('event', ['year' => '2026', 'slug' => 'spring-fair']));
    }

    public function test_an_umlaut_in_a_translated_address_is_refused_in_that_language(): void
    {
        $this->assertSame(['slug.de' => [self::segment()]], $this->errors('page', ['slug' => ['en' => 'about-us', 'de' => 'über-uns']]));
    }

    public function test_capitals_spaces_and_umlauts_are_refused_in_the_default_language(): void
    {
        foreach (['Über-uns', 'About us', 'About-Us'] as $slug) {
            $this->assertSame(['slug' => [self::segment()]], $this->errors('page', ['slug' => ['en' => $slug]]), $slug);
        }
    }

    public function test_every_field_of_the_route_is_checked(): void
    {
        $this->assertSame(['year' => [self::segment()]], $this->errors('event', ['year' => '2026 ', 'slug' => 'fair']));
    }

    public function test_a_catch_all_address_may_hold_slashes(): void
    {
        $this->assertSame([], $this->errors('doc', ['path' => 'guides/getting-started']));
        $this->assertSame(['path' => [(new Message('validation.slug.path'))->english()]], $this->errors('doc', ['path' => 'guides/Erste-Schritte']));
    }

    public function test_fields_outside_the_route_and_types_without_one_are_free(): void
    {
        $this->assertSame([], $this->errors('note', ['slug' => 'Über uns']));
        $this->assertSame([], $this->errors('doc', ['path' => 'a', 'title' => 'Über uns']));
    }

    public function test_a_readonly_address_field_is_not_checked(): void
    {
        $this->assertSame([], $this->errors('locked', ['slug' => 'Über']));
    }

    public function test_a_language_map_on_a_one_language_site_is_judged_by_its_default_value(): void
    {
        $this->registry = new TypeRegistry($this->dir, Locales::single('en'));
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'about-us']]));
        $this->assertSame(['slug' => [self::segment()]], $this->errors('page', ['slug' => ['en' => 'Über']]));
    }

    public function test_without_a_resolver_addresses_are_not_checked(): void
    {
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $rules = (new RecordValidator($fieldTypes, $this->dm))->rules($this->registry->get('page'));

        $this->assertArrayNotHasKey('slug', $rules);
    }
}
