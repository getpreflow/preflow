<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\Audits;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Review Focus 4: a page that writes on GET (a view counter) under the SEO audit renders as a
 * visit would, its write is skipped (the store is unchanged), and the audit report lists the
 * skipped write for that page.
 */
final class AuditSkippedWritesTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->dir = sys_get_temp_dir() . '/audit_writes_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config/models', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'audit-writes-test-key-0123456789ab','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(ViewCounterRoute::class, true) . "];\n");
        file_put_contents($this->dir . '/config/data.php', '<?php return ' . var_export([
            'default' => 'json',
            'drivers' => ['json' => ['driver' => 'json', 'path' => $this->dir . '/storage/data']],
            'models_path' => $this->dir . '/config/models',
        ], true) . ";\n");
        file_put_contents($this->dir . '/config/models/counter.json', json_encode([
            'key' => 'counter', 'table' => 'counter', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['views' => ['type' => 'integer']],
        ]));
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_audit_reports_skipped_writes_per_page(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();

        $report = $container->get(Audits::class)->quick(null, '/counter', 60);

        $page = 'https://site.test/counter';
        $this->assertSame(0, ViewCounterRoute::views($container), 'the store is unchanged');
        $this->assertEquals([$page => [new SkippedWrite('json', 'counter', 'home', 'save')]], $report->skippedWrites, 'the report lists the page\'s one skipped write');
        $this->assertSame(
            [$page => [['storage' => 'json', 'type' => 'counter', 'id' => 'home', 'operation' => 'save']]],
            $report->toArray()['skipped_writes'],
        );

        // Saved and read back with the report.
        $file = $this->dir . '/storage/data/report.json';
        $report->save($file);
        $this->assertEquals($report->skippedWrites, AuditReport::load($file)?->skippedWrites);

        // The audit saw what a visitor sees; a visit really counts.
        $visit = (string) $app->handle(new ServerRequest('GET', 'https://site.test/counter'))->getBody();
        $this->assertSame('<html><head><title>Counter</title></head><body><p>views: 1</p></body></html>', $visit);
        $this->assertSame(1, ViewCounterRoute::views($container));
        $this->assertSame('views: 1', ViewCounterRoute::$printed['audit'] ?? null, 'the audited page printed what the first visit printed');
    }
}

/** /counter counts its views on GET (a write) and prints the count. */
final class ViewCounterRoute implements MiddlewareInterface
{
    /** @var array<string, string> what each render printed: 'audit' for the internal one */
    public static array $printed = [];

    public function __construct(private readonly Container $container) {}

    public static function views(Container $container): int
    {
        $record = $container->get(DataManager::class)->findType('counter', 'home');

        return (int) ($record?->get('views') ?? 0);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/counter') {
            return $handler->handle($request);
        }
        $data = $this->container->get(DataManager::class);
        $views = self::views($this->container) + 1;
        $data->saveType(new DynamicRecord($this->container->get(TypeRegistry::class)->get('counter'), ['uuid' => 'home', 'views' => $views]));
        $text = 'views: ' . $views;
        if (Contexts::current()->purpose->leavesNoTrace()) {
            self::$printed['audit'] = $text;
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><head><title>Counter</title></head><body><p>' . $text . '</p></body></html>');
    }
}
