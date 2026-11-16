<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\JsonErrorRenderer;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Model;
use Preflow\Data\TypeAction;
use Preflow\Data\TypeActionRefused;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/**
 * A type's editor actions ("readonly": true, "creatable": false): what any adapter (Folio, an API)
 * may do with its records. The option is the type's, read by the core; code (seeders, migrations,
 * DataManager from the app) is not restricted by it.
 */
final class TypeActionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_type_actions_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $options */
    private function model(string $key, array $options = [], string $storage = 'default'): void
    {
        file_put_contents($this->dir . '/models/' . $key . '.json', json_encode([
            'key' => $key,
            'table' => $key . 's',
            'storage' => $storage,
            'fields' => ['question' => ['type' => 'string'], 'is_read' => ['type' => 'boolean']],
        ] + $options));
    }

    public function test_a_type_allows_every_action_by_default(): void
    {
        $this->model('note');
        $def = (new TypeRegistry($this->dir . '/models'))->get('note');

        $this->assertFalse($def->readonly);
        $this->assertTrue($def->creatable);
        foreach (TypeAction::cases() as $action) {
            $this->assertTrue($def->allows($action), $action->value);
        }
    }

    public function test_a_read_only_type_allows_no_create_update_or_delete(): void
    {
        $this->model('faq', ['readonly' => true]);
        $def = (new TypeRegistry($this->dir . '/models'))->get('faq');

        $this->assertTrue($def->readonly);
        $this->assertFalse($def->creatable, 'a read-only type creates nothing');
        $this->assertFalse($def->allows(TypeAction::Create));
        $this->assertFalse($def->allows(TypeAction::Update));
        $this->assertFalse($def->allows(TypeAction::Delete));
    }

    public function test_a_type_that_is_not_creatable_still_updates_and_deletes(): void
    {
        $this->model('message', ['creatable' => false]);
        $def = (new TypeRegistry($this->dir . '/models'))->get('message');

        $this->assertFalse($def->readonly);
        $this->assertFalse($def->allows(TypeAction::Create));
        $this->assertTrue($def->allows(TypeAction::Update));
        $this->assertTrue($def->allows(TypeAction::Delete));
    }

    public function test_explicit_defaults_are_accepted(): void
    {
        $this->model('note', ['readonly' => false, 'creatable' => true]);

        $this->assertTrue((new TypeRegistry($this->dir . '/models'))->get('note')->allows(TypeAction::Create));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'readonly as text' => [['readonly' => 'yes'], 'Type "faq": "readonly" is true or false'];
        yield 'readonly as a number' => [['readonly' => 1], 'Type "faq": "readonly" is true or false'];
        yield 'creatable as text' => [['creatable' => 'no'], 'Type "faq": "creatable" is true or false'];
        yield 'creatable as null' => [['creatable' => null], 'Type "faq": "creatable" is true or false'];
        yield 'read-only but creatable' => [['readonly' => true, 'creatable' => true], 'Type "faq" is read-only, so it creates nothing: drop "creatable"'];
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function test_an_invalid_value_is_refused_at_boot_naming_the_option(array $options, string $message): void
    {
        $this->model('faq', $options);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new TypeRegistry($this->dir . '/models'); // at boot, before any request uses the type
    }

    public function test_a_runtime_definition_takes_the_options(): void
    {
        $registry = new TypeRegistry($this->dir . '/models');
        $def = $registry->define(['key' => 'inbox', 'table' => 'inbox', 'creatable' => false, 'fields' => ['body' => ['type' => 'text']]]);

        $this->assertFalse($def->allows(TypeAction::Create));
        $this->assertTrue($def->allows(TypeAction::Update));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "bad": "readonly" is true or false');
        $registry->define(['key' => 'bad', 'table' => 'bad', 'readonly' => 'true', 'fields' => ['body' => ['type' => 'text']]]);
    }

    public function test_a_model_class_declares_them_on_its_entity(): void
    {
        $registry = new TypeRegistry($this->dir . '/models', classes: ['ta_faq' => TypeActionsFaq::class, 'ta_inbox' => TypeActionsInbox::class]);

        $this->assertTrue($registry->get('ta_faq')->readonly);
        $this->assertFalse($registry->get('ta_faq')->allows(TypeAction::Update));
        $this->assertFalse($registry->get('ta_inbox')->allows(TypeAction::Create));
        $this->assertTrue($registry->get('ta_inbox')->allows(TypeAction::Delete));
        $this->assertSame(['readonly' => true], array_intersect_key($registry->schema('ta_faq'), ['readonly' => 0, 'creatable' => 0]));
    }

    public function test_a_model_classs_admin_file_may_set_them(): void
    {
        file_put_contents($this->dir . '/models/ta_plain.json', json_encode(['key' => 'ta_plain', 'label' => 'Plain', 'readonly' => true]));
        $registry = new TypeRegistry($this->dir . '/models', classes: ['ta_plain' => TypeActionsPlain::class]);

        $this->assertFalse($registry->get('ta_plain')->allows(TypeAction::Delete));
    }

    public function test_a_class_and_its_admin_file_never_disagree_silently_checked_at_boot(): void
    {
        file_put_contents($this->dir . '/models/ta_inbox.json', json_encode(['key' => 'ta_inbox', 'label' => 'Inbox', 'creatable' => true]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Type "ta_inbox" has "creatable": false on ' . TypeActionsInbox::class . ' and true in ta_inbox.json: set it in one place.');
        new TypeRegistry($this->dir . '/models', classes: ['ta_inbox' => TypeActionsInbox::class]); // at boot
    }

    public function test_an_adapter_is_refused_with_a_403_naming_the_type_and_action(): void
    {
        $this->model('faq', ['readonly' => true]);
        $this->model('message', ['creatable' => false]);
        $registry = new TypeRegistry($this->dir . '/models');

        $registry->get('message')->assertAllows(TypeAction::Update); // allowed: no exception
        try {
            $registry->get('message')->assertAllows(TypeAction::Create);
            $this->fail('a create of a type that is not creatable passed');
        } catch (TypeActionRefused $e) {
            $this->assertInstanceOf(ForbiddenHttpException::class, $e);
            $this->assertSame(403, $e->statusCode);
            $this->assertSame('message', $e->type);
            $this->assertSame(TypeAction::Create, $e->action);
            $this->assertSame('Records of "message" are not created here.', $e->getMessage());
        }
        try {
            $registry->get('faq')->assertAllows(TypeAction::Delete);
            $this->fail('a delete of a read-only type passed');
        } catch (TypeActionRefused $e) {
            $this->assertSame('"faq" is read-only: its records are not created, changed or deleted here.', $e->getMessage());
        }
    }

    /** A JSON API (core's API prefixes) answers the refusal as a JSON 403, as any other HttpException. */
    public function test_an_api_request_gets_a_json_403(): void
    {
        $this->model('faq', ['readonly' => true]);
        $def = (new TypeRegistry($this->dir . '/models'))->get('faq');
        $handler = new ErrorHandler(new ProdErrorRenderer(), null, new ApiConfig(), new JsonErrorRenderer(false));

        foreach ([['POST', TypeAction::Create], ['PATCH', TypeAction::Update], ['DELETE', TypeAction::Delete]] as [$method, $action]) {
            try {
                $def->assertAllows($action);
                $this->fail("{$method} passed");
            } catch (TypeActionRefused $e) {
                $response = $handler->handleException($e, (new Psr17Factory())->createServerRequest($method, '/api/faq/1'));
                $this->assertSame(403, $response->getStatusCode(), $method);
                $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
                $this->assertSame('{"message":"\"faq\" is read-only: its records are not created, changed or deleted here."}', (string) $response->getBody());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    /** Seeders, migrations and the app's own code write as before: the option is about editors, not storage. */
    #[\PHPUnit\Framework\Attributes\DataProvider('storages')]
    public function test_code_still_creates_updates_and_deletes_records_of_a_read_only_type(string $storage): void
    {
        $this->model('faq', ['readonly' => true], $storage);
        $this->model('message', ['creatable' => false], $storage);
        if ($storage === 'sqlite') {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE faqs (uuid TEXT PRIMARY KEY, question TEXT, is_read INTEGER)');
            $pdo->exec('CREATE TABLE messages (uuid TEXT PRIMARY KEY, question TEXT, is_read INTEGER)');
            $driver = new SqliteDriver($pdo);
        } else {
            $driver = new JsonFileDriver($this->dir . '/data');
        }
        $dm = new DataManager([$storage => $driver, 'default' => $driver], typeRegistry: new TypeRegistry($this->dir . '/models'));

        foreach (['faq', 'message'] as $type) {
            $def = $dm->getTypeRegistry()->get($type);
            $dm->saveType(new DynamicRecord($def, ['uuid' => 'a', 'question' => 'Seeded?', 'is_read' => false]));
            $record = $dm->findType($type, 'a');
            $this->assertSame('Seeded?', $record?->get('question'), $type);
            $record->set('question', 'Seeded again');
            $dm->saveType($record);
            $this->assertSame('Seeded again', $dm->findType($type, 'a')?->get('question'), $type);
            $dm->deleteType($type, 'a');
            $this->assertNull($dm->findType($type, 'a'), $type);
        }
    }

    public function test_a_hand_built_definition_takes_them_too(): void
    {
        $def = new TypeDefinition(key: 'x', table: 'x', storage: 'default', fields: [], readonly: true);

        $this->assertFalse($def->allows(TypeAction::Create));
        $this->assertFalse((new TypeDefinition(key: 'y', table: 'y', storage: 'default', fields: [], creatable: false))->allows(TypeAction::Create));
    }
}

#[Entity(table: 'ta_faqs', type: 'ta_faq', readonly: true)]
final class TypeActionsFaq extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $question = '';
}

#[Entity(table: 'ta_inbox', type: 'ta_inbox', creatable: false)]
final class TypeActionsInbox extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $body = '';
}

#[Entity(table: 'ta_plain', type: 'ta_plain')]
final class TypeActionsPlain extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $body = '';
}
