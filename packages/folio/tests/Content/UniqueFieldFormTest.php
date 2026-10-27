<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Storage\TableSync;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;

/** A PDO that runs a hook before each prepare(): another request's write lands in between. */
final class UniqueFormPdo extends \PDO
{
    /** @var (\Closure(string): void)|null */
    public ?\Closure $beforePrepare = null;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->beforePrepare !== null) {
            ($this->beforePrepare)($query);
        }

        return parent::prepare($query, $options);
    }
}

/**
 * A taken unique value in Folio's form is a field error on the re-rendered form (422), never a
 * 500: found by validation, and refused by the unique index when another save took it between
 * validation and write. Publishing types too (their draft save skips the other rules).
 */
final class UniqueFieldFormTest extends TestCase
{
    private const string TAKEN = 'This value is already taken.';

    private string $dir;
    private UniqueFormPdo $pdo;
    private DataManager $dm;
    private TypeRegistry $registry;
    private TypeCatalog $catalog;
    /** @var array<string, mixed> the last form's template context */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_unique_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/m', 0777, true);
        foreach (['page' => false, 'post' => true] as $key => $publishing) {
            file_put_contents($this->dir . "/m/{$key}.json", json_encode([
                'key' => $key, 'table' => $key, 'storage' => 'sqlite', 'id_field' => 'uuid', 'label' => ucfirst($key), 'publishing' => $publishing,
                'fields' => [
                    'title' => ['type' => 'string', 'validate' => ['required']],
                    'slug' => ['type' => 'string', 'unique' => true],
                ],
            ]));
        }
        $this->pdo = new UniqueFormPdo('sqlite:' . $this->dir . '/db.sqlite');
        $this->registry = new TypeRegistry($this->dir . '/m');
        $this->catalog = new TypeCatalog($this->dir . '/m');
        $this->dm = new DataManager(['sqlite' => new SqliteDriver($this->pdo)], 'sqlite', $this->registry, new ValidatorFactory(new RuleFactory()));
        (new TableSync($this->catalog, $this->registry, $this->dm))->sync();
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->dm);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function testFolioFormShowsAlreadyTaken(): void
    {
        foreach (['page', 'post'] as $type) {
            $this->stored($type, ['uuid' => 'a', 'title' => 'A', 'slug' => 'x']);
            $this->stored($type, ['uuid' => 'b', 'title' => 'B', 'slug' => 'y']);

            $response = $this->update($type, 'b', ['title' => 'B2', 'slug' => 'x']);

            $this->assertSame(422, $response->getStatusCode(), $type);
            $this->assertStringContainsString(self::TAKEN, $this->renderedField('slug'), $type);
            $this->assertSame(['B', 'y'], [$this->value($type, 'b', 'title'), $this->value($type, 'b', 'slug')], $type);

            $response = $this->store($type, ['title' => 'C', 'slug' => 'x']);
            $this->assertSame(422, $response->getStatusCode(), $type);
            $this->assertStringContainsString(self::TAKEN, $this->renderedField('slug'), $type);
            $this->assertSame("/folio/{$type}", $this->rendered['action'], 'a new record\'s form creates it again');
            $this->assertCount(2, $this->dm->queryType($type)->get()->items());
        }
    }

    public function testFolioFormShowsAlreadyTakenWhenTheDatabaseRefuses(): void
    {
        foreach (['page', 'post'] as $type) {
            $this->stored($type, ['uuid' => 'b', 'title' => 'B', 'slug' => 'y']);
            // Another request saves slug "x" after this save checked it, before it writes (its
            // next statement on the record).
            $other = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
            $checked = false;
            $this->pdo->beforePrepare = function (string $sql) use ($other, $type, &$checked): void {
                if (str_contains($sql, "FROM \"{$type}\" WHERE \"slug\"")) {
                    $checked = true;
                } elseif ($checked && (str_contains($sql, "FROM \"{$type}\" WHERE \"uuid\"") || str_starts_with($sql, "INSERT INTO \"{$type}\""))) {
                    $this->pdo->beforePrepare = null;
                    $other->exec("INSERT INTO \"{$type}\" (uuid, title, slug) VALUES ('a', 'A', 'x')");
                }
            };

            $response = $this->update($type, 'b', ['title' => 'B2', 'slug' => 'x']);

            $this->assertNull($this->pdo->beforePrepare, "{$type}: the other save ran");
            $this->assertSame(422, $response->getStatusCode(), $type);
            $this->assertStringContainsString(self::TAKEN, $this->renderedField('slug'), $type);
            $this->assertSame(['B', 'y'], [$this->value($type, 'b', 'title'), $this->value($type, 'b', 'slug')], $type);
            $this->assertSame('x', $this->value($type, 'a', 'slug'), $type);
        }
    }

    /** @param array<string, mixed> $data */
    private function stored(string $type, array $data): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get($type), $data), false);
    }

    private function value(string $type, string $id, string $field): mixed
    {
        return $this->dm->findType($type, $id)?->get($field);
    }

    /** @param array<string, mixed> $post */
    private function update(string $type, string $id, array $post): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', "/folio/{$type}/{$id}")
            ->withAttribute('type', $type)->withAttribute('id', $id)->withParsedBody($post);

        return $this->controller()->update($request);
    }

    /** @param array<string, mixed> $post */
    private function store(string $type, array $post): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', "/folio/{$type}")
            ->withAttribute('type', $type)->withParsedBody($post);

        return $this->controller()->store($request);
    }

    /** The rendered editor of a field in the last form. */
    private function renderedField(string $name): string
    {
        foreach ($this->rendered['fields'] ?? [] as $field) {
            if ($field['name'] === $name) {
                return (string) $field['html'];
            }
        }
        $this->fail("no field {$name} in the form");
    }

    private function controller(): AdminController
    {
        $fields = new FieldTypeRegistry();
        $fields->register(new StringFieldType());
        $rendered = &$this->rendered;
        $engine = new class ($rendered) implements TemplateEngineInterface {
            /** @param array<string, mixed> $rendered */
            public function __construct(private array &$rendered) {}
            public function render(string $template, array $context = []): string
            {
                if (isset($context['fields'])) {
                    $this->rendered = $context;
                }

                return $template;
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
        $validator = new RecordValidator($fields, $this->dm);

        return new AdminController(
            $this->catalog, $this->registry, $this->dm, $engine,
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $fields, '/folio', new RecordLabeler(), new FrontendResolver($this->dm, 'page'),
            validator: $validator,
            publisher: new Publisher($this->dm, $this->catalog, $validator),
        );
    }
}
