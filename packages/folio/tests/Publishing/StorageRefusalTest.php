<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Http\PublishingController;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\MysqlTestDatabase;

/**
 * The paths that save without the rules (autosave, restoring a revision) answer a value the
 * storage refuses (a taken unique value, a bounded column's overlong value) as a field error,
 * never a 500, and save nothing.
 */
final class StorageRefusalTest extends TestCase
{
    use PublishingScaffold;

    private const string TAKEN = 'This value is already taken.';
    private const string TOO_LONG = 'Must be at most 191 characters.';

    protected function tearDown(): void
    {
        if (!MysqlTestDatabase::configured()) {
            $this->storage = 'json'; // a skipped MySQL test made no tables
        }
        $this->removeScaffold();
    }

    public function testAutosaveReportsATakenValue(): void
    {
        $this->autosaveReportsATakenValue('sqlite');
    }

    #[Group('mysql')]
    public function testAutosaveReportsATakenValueOnMysql(): void
    {
        $this->autosaveReportsATakenValue('mysql');
    }

    public function testAutosaveReportsAnOverlongValue(): void
    {
        $this->autosaveReportsAnOverlongValue('sqlite');
    }

    /** Without the check MySQL refuses the write itself (1406 Data too long): a 500. */
    #[Group('mysql')]
    public function testAutosaveReportsAnOverlongValueOnMysql(): void
    {
        $this->autosaveReportsAnOverlongValue('mysql');
    }

    public function testRestoreOfATakenValueFlashesTheError(): void
    {
        $this->restoreOfATakenValueFlashesTheError('sqlite');
    }

    #[Group('mysql')]
    public function testRestoreOfATakenValueFlashesTheErrorOnMysql(): void
    {
        $this->restoreOfATakenValueFlashesTheError('mysql');
    }

    /** Discarding goes back to the published content, whose unique value another record may have taken since. */
    public function testDiscardOfATakenValueFlashesTheError(): void
    {
        $this->scaffoldEvents('sqlite');
        $this->publisher()->publish($this->working('event', 'a', ['name' => 'A', 'slug' => 'x']), null);
        $a = $this->stored('event', 'a');
        $a->set('slug', 'z');
        $this->publisher()->saveDraft($a, null);
        $this->working('event', 'b', ['name' => 'B', 'slug' => 'x']);
        $session = $this->session();

        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/event/a/discard')
            ->withAttribute('type', 'event')->withAttribute('id', 'a')
            ->withAttribute(SessionInterface::class, $session);
        $response = $this->app()->container()->get(PublishingController::class)->discard($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/event/a/edit', $response->getHeaderLine('Location'));
        $this->assertSame(['error' => 'Not discarded — Slug: ' . self::TAKEN], $session->flashes);
        $this->assertSame('z', $this->stored('event', 'a')->get('slug'));
    }

    /** A revision saved before fingerprints existed clears the working row's current ones (SQL leaves unnamed columns alone). */
    public function testRestoreOfARevisionWithoutFingerprintsClearsThem(): void
    {
        $this->scaffoldEvents('sqlite');
        $this->publisher()->publish($this->working('event', 'a', ['name' => 'A', 'slug' => 'x']), null);
        $a = $this->stored('event', 'a');
        $a->set('name', 'A2');
        $a->set('_translated_from', ['de' => ['name' => 'aaaaaaaaaaaaaaaa']]);
        $this->publisher()->publish($a, null);
        $this->assertNotEmpty($this->stored('event', 'a')->get('_translated_from'));
        $first = $this->publisher()->revisions('event', 'a')[1];
        $this->assertArrayNotHasKey('_translated_from', $first->data);

        $this->publisher()->restore('event', 'a', $first->id);

        $this->assertSame('A', $this->stored('event', 'a')->get('name'));
        $this->assertEmpty($this->stored('event', 'a')->get('_translated_from'));
    }

    private function autosaveReportsATakenValue(string $storage): void
    {
        $this->scaffoldEvents($storage);
        $this->working('event', 'a', ['name' => 'A', 'slug' => 'x', '_version' => 1]);
        $this->working('event', 'b', ['name' => 'B', 'slug' => 'y', '_version' => 1]);

        $response = $this->post('/folio/event/b/autosave', ['name' => 'B2', 'slug' => 'x', '_locale' => 'en', '_version' => '1']);

        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $body);
        $this->assertStringContainsString('is-conflict', $body);
        $this->assertStringContainsString('Not saved', $body);
        $this->assertStringContainsString('Slug: ' . self::TAKEN, $body);
        $this->assertStringNotContainsString('folio-version', $body, 'the editor keeps its version');
        $this->assertSame(['B', 'y', 1], [$this->stored('event', 'b')->get('name'), $this->stored('event', 'b')->get('slug'), (int) $this->stored('event', 'b')->get('_version')]);
    }

    private function autosaveReportsAnOverlongValue(string $storage): void
    {
        $this->scaffoldEvents($storage);
        $this->working('event', 'b', ['name' => 'B', 'slug' => 'y', '_version' => 1]);

        $response = $this->post('/folio/event/b/autosave', ['name' => 'B2', 'slug' => str_repeat('s', 192), '_locale' => 'en', '_version' => '1']);

        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $body);
        $this->assertStringContainsString('is-conflict', $body);
        $this->assertStringContainsString('Slug: ' . self::TOO_LONG, $body);
        $this->assertSame(['B', 'y'], [$this->stored('event', 'b')->get('name'), $this->stored('event', 'b')->get('slug')]);

        $fits = $this->post('/folio/event/b/autosave', ['name' => 'B2', 'slug' => str_repeat('s', 191), '_locale' => 'en', '_version' => '1']);
        $this->assertStringNotContainsString('is-conflict', (string) $fits->getBody());
        $this->assertSame(str_repeat('s', 191), $this->stored('event', 'b')->get('slug'));
    }

    private function restoreOfATakenValueFlashesTheError(string $storage): void
    {
        $this->scaffoldEvents($storage);
        $this->publisher()->publish($this->working('event', 'a', ['name' => 'A', 'slug' => 'x']), null);
        $a = $this->stored('event', 'a');
        $a->set('slug', 'z');
        $this->publisher()->publish($a, null);
        $this->working('event', 'b', ['name' => 'B', 'slug' => 'x']);
        $first = $this->publisher()->revisions('event', 'a')[1];
        $session = $this->session();

        $request = (new Psr17Factory())->createServerRequest('POST', "/folio/event/a/revisions/{$first->id}/restore")
            ->withAttribute('type', 'event')->withAttribute('id', 'a')->withAttribute('rev', $first->id)
            ->withAttribute(SessionInterface::class, $session);
        $response = $this->app()->container()->get(PublishingController::class)->restore($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/event/a/edit', $response->getHeaderLine('Location'));
        $this->assertSame(['error' => 'Not restored — Slug: ' . self::TAKEN], $session->flashes);
        $this->assertSame('z', $this->stored('event', 'a')->get('slug'));
        $this->assertSame('x', $this->stored('event', 'b')->get('slug'));
    }

    private function scaffoldEvents(string $storage): void
    {
        $this->scaffoldPublishing($storage);
        $this->writeModel('event', [
            'label' => 'Events',
            'publishing' => true,
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'label' => 'Slug', 'unique' => true],
            ],
        ]);
        ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }

    /** A session that records flashes. */
    private function session(): SessionInterface
    {
        return new class implements SessionInterface {
            /** @var array<string, mixed> */
            public array $flashes = [];
            public function start(): void {}
            public function getId(): string { return 'test'; }
            public function regenerate(): void {}
            public function invalidate(): void {}
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function remove(string $key): void {}
            public function flash(string $key, mixed $value): void { $this->flashes[$key] = $value; }
            public function getFlash(string $key, mixed $default = null): mixed { return $this->flashes[$key] ?? $default; }
            public function isStarted(): bool { return true; }
            public function ageFlash(): void {}
            public function close(): void {}
        };
    }
}
