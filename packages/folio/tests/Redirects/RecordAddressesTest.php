<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Redirects;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Tests\Support\FolioPublisher;
use Preflow\Publishing\Publisher;
use Preflow\Folio\Redirects\AddressTable;
use Preflow\Folio\Redirects\FolioRedirectTargets;
use Preflow\Folio\Redirects\LiveRecords;
use Preflow\Folio\Redirects\NotFoundEntries;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Redirects\RemovalTarget;
use Preflow\Folio\Redirects\SiteTarget;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * The address book on its own (the publisher is not hooked up here, so each test calls sync()
 * itself): a publishing "page" at /{slug} (en, de, translated slugs), a non-publishing "post"
 * at /news/{slug} shown when its status is "published", and a "memo" without a route.
 */
final class RecordAddressesTest extends TestCase
{
    private string $dir;

    private DataManager $dm;

    private Publisher $publisher;

    private FrontendResolver $resolver;

    private LiveRecords $live;

    private RedirectEntries $entries;

    private RecordAddresses $addresses;

    private NotFoundEntries $notFound;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_addresses_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $translatable = [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ];
        $this->model('page', ['publishing' => true, 'frontend' => ['route' => '/{slug}'], 'fields' => $translatable]);
        $this->model('post', ['publishing' => false, 'frontend' => ['route' => '/news/{slug}'], 'fields' => [
            'slug' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]]);
        $this->model('memo', ['publishing' => true, 'fields' => $translatable]);

        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store'), 'default' => new JsonFileDriver($this->dir . '/internal')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $catalog = new TypeCatalog($this->dir . '/models');
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $this->publisher = FolioPublisher::make($this->dm, $catalog, new RecordValidator($fieldTypes, $this->dm));
        $this->resolver = FrontendResolver::fromCatalog($catalog, $this->dm);
        $this->live = new LiveRecords($this->dm, $this->publisher, $this->resolver);
        $this->entries = new RedirectEntries($this->dm);
        $this->notFound = new NotFoundEntries($this->dm);
        $this->addresses = new RecordAddresses($this->dm, $this->entries, $this->resolver, $this->live, notFound: $this->notFound, targets: new SiteTarget(
            $this->entries,
            $this->live,
            $this->resolver,
            new Locales(['en', 'de'], 'en'),
            true,
            'example.com',
        ));
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", (string) json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid'] + $config,
        ));
    }

    /** @param array<string, mixed> $data */
    private function working(string $type, string $id, array $data): DynamicRecord
    {
        $record = DynamicRecord::fromArray($this->dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $this->dm->saveType($record, false);

        return $record;
    }

    /** Publishes the page with these slugs (and visible in their languages), without syncing. @param array<string, string> $slug */
    private function publish(string $id, array $slug): void
    {
        $this->publisher->publish($this->working('page', $id, ['title' => $slug, 'slug' => $slug, '_locales' => array_keys($slug)]), null);
    }

    /** @param array<string, string> $slug */
    private function publishAndSync(string $id, array $slug): void
    {
        $this->publish($id, $slug);
        $this->addresses->sync('page', $id);
    }

    /** @return list<string> "locale path → target (kind, source)" of every entry */
    private function entryLines(): array
    {
        return array_map(static fn (array $row): string => sprintf(
            '%s %s%s → %s (%s, %s, %d)',
            $row['locale'],
            $row['path'],
            $row['area'] ? '/*' : '',
            $row['target_url'] ?? ($row['target_type'] !== null ? $row['target_type'] . ':' . $row['target_id'] : '-'),
            $row['kind'],
            $row['source_type'] !== null ? $row['source_type'] . ':' . $row['source_id'] : '-',
            $row['status'],
        ), $this->entries->all());
    }

    public function test_first_sync_only_records(): void
    {
        $this->publish('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        $this->assertFalse($this->addresses->sync('page', 'p1'));

        $this->assertSame(['en' => '/about', 'de' => '/ueber-uns'], $this->addresses->of('page', 'p1'));
        $this->assertSame([], $this->entries->all());
    }

    public function test_rename_writes_an_auto_entry_to_the_record(): void
    {
        $this->publishAndSync('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        $this->publishAndSync('p1', ['en' => 'about-us', 'de' => 'ueber-uns']);

        $this->assertSame(['en /about → page:p1 (auto, page:p1, 301)'], $this->entryLines());
        $this->assertSame(['en' => '/about-us', 'de' => '/ueber-uns'], $this->addresses->of('page', 'p1'));
    }

    public function test_rename_twice_both_old_addresses_point_to_the_record(): void
    {
        $this->publishAndSync('p1', ['en' => 'a']);
        $this->publishAndSync('p1', ['en' => 'b']);
        $this->publishAndSync('p1', ['en' => 'c']);

        $this->assertSame([
            'en /a → page:p1 (auto, page:p1, 301)',
            'en /b → page:p1 (auto, page:p1, 301)',
        ], $this->entryLines());
        $this->assertSame(['en' => '/c'], $this->addresses->of('page', 'p1'));
    }

    public function test_rename_back_drops_the_entry_and_the_page_wins(): void
    {
        $this->publishAndSync('p1', ['en' => 'a']);
        $this->publishAndSync('p1', ['en' => 'b']);
        $this->assertNotNull($this->entries->at('en', '/a'));

        $this->publishAndSync('p1', ['en' => 'a']);

        $this->assertNull($this->entries->at('en', '/a'), 'the page holds /a again');
        $this->assertSame(['en /b → page:p1 (auto, page:p1, 301)'], $this->entryLines());
        $this->assertSame(['en' => '/a'], $this->addresses->of('page', 'p1'));
    }

    public function test_cleared_translated_slug_redirects_that_language_to_the_default(): void
    {
        $this->publishAndSync('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        // The German slug is cleared; the page stays visible in German.
        $this->publisher->publish($this->working('page', 'p1', [
            'title' => ['en' => 'about', 'de' => 'Über uns'], 'slug' => ['en' => 'about', 'de' => ''], '_locales' => ['en', 'de'],
        ]), null);
        $this->assertFalse($this->addresses->sync('page', 'p1'));

        $this->assertSame(['de /ueber-uns → page:p1 (auto, page:p1, 301)'], $this->entryLines());
        $this->assertSame(['en' => '/about'], $this->addresses->of('page', 'p1'));
        $this->assertSame('/about', (new FolioRedirectTargets($this->live, $this->resolver))->url('page', 'p1', 'de'));
    }

    public function test_previous_live_is_used_when_nothing_is_stored(): void
    {
        $this->publish('p1', ['en' => 'about']);
        $previous = $this->publisher->snapshot('page', 'p1');
        $this->publish('p1', ['en' => 'about-us']);

        $this->addresses->sync('page', 'p1', $previous);

        $this->assertSame(['en /about → page:p1 (auto, page:p1, 301)'], $this->entryLines());
        $this->assertSame(['en' => '/about-us'], $this->addresses->of('page', 'p1'));
    }

    public function test_leaving_the_site_without_target_writes_untargeted_removed_entries(): void
    {
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->assertFalse($this->addresses->sync('post', 'n1'));
        $this->assertSame(['en' => '/news/hello', 'de' => '/news/hello'], $this->addresses->of('post', 'n1'));

        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'draft']);
        $this->assertTrue($this->addresses->sync('post', 'n1'), 'the record has just left the site');

        $this->assertSame([
            'de /news/hello → - (removed, post:n1, 301)',
            'en /news/hello → - (removed, post:n1, 301)',
        ], $this->entryLines());
        $this->assertSame([], $this->addresses->of('post', 'n1'));
        $this->assertFalse($this->addresses->sync('post', 'n1'), 'already off the site');
    }

    public function test_coming_back_repoints_its_untargeted_entries(): void
    {
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->addresses->sync('post', 'n1');
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'draft']);
        $this->addresses->sync('post', 'n1');

        $this->working('post', 'n1', ['slug' => 'hello-again', 'status' => 'published']);
        $this->assertFalse($this->addresses->sync('post', 'n1'));

        $this->assertSame([
            'de /news/hello → post:n1 (auto, post:n1, 301)',
            'en /news/hello → post:n1 (auto, post:n1, 301)',
        ], $this->entryLines());
        $this->assertSame(['en' => '/news/hello-again', 'de' => '/news/hello-again'], $this->addresses->of('post', 'n1'));

        // Back at its first address: the page wins there.
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->addresses->sync('post', 'n1');
        $this->assertSame([
            'de /news/hello-again → post:n1 (auto, post:n1, 301)',
            'en /news/hello-again → post:n1 (auto, post:n1, 301)',
        ], $this->entryLines());
    }

    public function test_removed_with_url_target(): void
    {
        $this->publishAndSync('p1', ['en' => 'a', 'de' => 'x']);
        $this->publishAndSync('p1', ['en' => 'b', 'de' => 'x']);
        // Hand-made entries pointing at the page: an area (prefix mode) and a temporary one.
        $this->entries->save(['path' => '/old-area', 'locale' => '', 'area' => true, 'mode' => 'prefix', 'target_type' => 'page', 'target_id' => 'p1', 'kind' => 'manual']);
        $this->entries->save(['path' => '/promo', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1', 'status' => 302, 'kind' => 'manual', 'hits' => 7]);
        $this->publisher->unpublish('page', 'p1');

        $this->addresses->removed('page', 'p1', RemovalTarget::parse('https://example.org/archive'));

        $this->assertSame([
            'en /a → https://example.org/archive (removed, page:p1, 301)',
            'en /b → https://example.org/archive (removed, page:p1, 301)',
            ' /old-area/* → https://example.org/archive (manual, -, 301)',
            'en /promo → https://example.org/archive (manual, -, 302)',
            'de /x → https://example.org/archive (removed, page:p1, 301)',
        ], $this->entryLines());
        $area = array_values(array_filter($this->entries->all(), static fn (array $r): bool => $r['area']))[0];
        $this->assertSame('prefix', $area['mode'], 'an area entry stays an area');
        $this->assertSame(7, $this->entries->at('en', '/promo')['hits'], 'counts are kept');
        $this->assertSame([], $this->addresses->of('page', 'p1'));
    }

    public function test_seed_records_addresses_without_entries(): void
    {
        $this->publish('p1', ['en' => 'about', 'de' => 'ueber-uns']);
        $this->publish('p2', ['en' => 'contact']);
        $this->working('page', 'draft', ['title' => ['en' => 'd'], 'slug' => ['en' => 'draft'], '_locales' => ['en']]);
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->working('post', 'n2', ['slug' => 'later', 'status' => 'draft']);
        $this->working('memo', 'm1', ['title' => ['en' => 'm'], 'slug' => ['en' => 'memo'], '_locales' => ['en']]);
        // p2 already has its rows, with an address it no longer holds: seed leaves recorded records alone.
        $this->dm->saveType(DynamicRecord::fromArray(AddressTable::definition(), [
            'id' => RecordAddresses::id('page', 'p2', 'en'), 'type' => 'page', 'record_id' => 'p2', 'locale' => 'en', 'path' => '/kontakt',
        ]), false);

        $lines = $this->addresses->seed();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('2', $lines[0]);
        $this->assertSame(['en' => '/about', 'de' => '/ueber-uns'], $this->addresses->of('page', 'p1'));
        $this->assertSame(['en' => '/kontakt'], $this->addresses->of('page', 'p2'));
        $this->assertSame(['en' => '/news/hello', 'de' => '/news/hello'], $this->addresses->of('post', 'n1'));
        $this->assertSame([], $this->addresses->of('page', 'draft'));
        $this->assertSame([], $this->addresses->of('post', 'n2'));
        $this->assertSame([], $this->entries->all());
    }

    public function test_umlaut_slugs_are_stored_in_the_one_form(): void
    {
        $this->publishAndSync('p1', ['en' => 'about', 'de' => 'über-uns']);
        $this->assertSame(['en' => '/about', 'de' => '/%C3%BCber-uns'], $this->addresses->of('page', 'p1'));

        $this->publishAndSync('p1', ['en' => 'about', 'de' => 'wir']);
        $this->assertSame('/%C3%BCber-uns', $this->entries->at('de', '/über-uns')['path'] ?? null);
    }

    public function test_storage_failure_is_swallowed(): void
    {
        $broken = new RecordAddresses($this->dm, $this->entries, $this->resolver, static fn (): LiveRecords => throw new \RuntimeException('no table'));
        $this->publish('p1', ['en' => 'about']);

        $this->assertFalse($broken->sync('page', 'p1'));
        $broken->removed('page', 'p1', null);
        $this->assertSame([], $broken->of('page', 'p1'));
    }

    private function notFoundAt(string $locale, string $path): bool
    {
        return $this->notFound->find(NotFoundEntries::id($locale, $path)) !== null;
    }

    public function test_an_address_a_page_takes_leaves_the_not_found_list(): void
    {
        $at = new \DateTimeImmutable('2026-11-01T10:00:00Z');
        $this->notFound->record('/about', 'en', $at);
        $this->notFound->record('/ueber-uns', 'de', $at);
        $this->notFound->record('/elsewhere', 'en', $at);

        $this->publishAndSync('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        $this->assertFalse($this->notFoundAt('en', '/about'));
        $this->assertFalse($this->notFoundAt('de', '/ueber-uns'));
        $this->assertTrue($this->notFoundAt('en', '/elsewhere'), 'other addresses stay listed');

        // Seeding records a page's addresses too: they leave the list.
        $this->notFound->record('/news/hello', 'en', $at);
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->addresses->seed();
        $this->assertFalse($this->notFoundAt('en', '/news/hello'));
    }

    public function test_addresses_that_get_an_entry_leave_the_not_found_list(): void
    {
        $at = new \DateTimeImmutable('2026-11-01T10:00:00Z');
        $this->publishAndSync('p1', ['en' => 'a']);
        // Hit while something went wrong (the rows were there; a cache served a 404).
        $this->notFound->record('/a', 'en', $at);
        $this->publishAndSync('p1', ['en' => 'b']);
        $this->assertNotNull($this->entries->at('en', '/a'), 'an automatic entry');
        $this->assertFalse($this->notFoundAt('en', '/a'));

        // Removed: the record's address gets a "removed" entry.
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->addresses->sync('post', 'n1');
        $this->notFound->record('/news/hello', 'de', $at);
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'draft']);
        $this->assertTrue($this->addresses->sync('post', 'n1'));
        $this->assertSame('removed', $this->entries->at('de', '/news/hello')['kind'] ?? null);
        $this->assertFalse($this->notFoundAt('de', '/news/hello'));
    }

    public function test_without_locale_prefixes_addresses_are_language_neutral(): void
    {
        $neutral = new RecordAddresses($this->dm, $this->entries, $this->resolver, $this->live, prefixed: false, notFound: $this->notFound);
        $at = new \DateTimeImmutable('2026-11-01T10:00:00Z');
        // Visits are recorded under the locale each request had.
        $this->notFound->record('/about', 'de', $at);
        $this->notFound->record('/about', 'en', $at);

        $this->publish('p1', ['en' => 'about', 'de' => 'ueber-uns']);
        $neutral->sync('page', 'p1');

        $this->assertSame(['' => '/about'], $neutral->of('page', 'p1'));
        $this->assertFalse($this->notFoundAt('de', '/about'));
        $this->assertFalse($this->notFoundAt('en', '/about'));

        $this->notFound->record('/about', 'de', $at);
        $this->publish('p1', ['en' => 'about-us', 'de' => 'ueber-uns']);
        $neutral->sync('page', 'p1');
        $this->assertSame([' /about → page:p1 (auto, page:p1, 301)'], $this->entryLines());
        $this->assertFalse($this->notFoundAt('de', '/about'), 'the address got an entry');
    }

    public function test_entries_of_others_survive_a_removal_without_target(): void
    {
        $this->publishAndSync('p1', ['en' => 'about']);
        $this->entries->save(['path' => '/team-old', 'locale' => '', 'target_type' => 'page', 'target_id' => 'p1', 'kind' => 'manual', 'status' => 302]);
        $this->entries->save(['path' => '/archive', 'locale' => '', 'area' => true, 'mode' => 'one', 'target_type' => 'page', 'target_id' => 'p1', 'kind' => 'manual']);
        $targets = new FolioRedirectTargets($this->live, $this->resolver);

        $this->publisher->unpublish('page', 'p1');
        $this->addresses->removed('page', 'p1', null);

        $this->assertSame([
            'en /about → - (removed, page:p1, 301)',
            ' /archive/* → page:p1 (manual, -, 301)',
            ' /team-old → page:p1 (manual, -, 302)',
        ], $this->entryLines());
        $this->assertNull($targets->url('page', 'p1', 'en'), 'a 404 while the page is off');

        $this->publishAndSync('p1', ['en' => 'about']);

        $this->assertSame('/about', $targets->url('page', 'p1', 'en'), 'the hand-made entry works again');
        $this->assertSame([
            ' /archive/* → page:p1 (manual, -, 301)',
            ' /team-old → page:p1 (manual, -, 302)',
        ], $this->entryLines());
    }

    public function test_seed_goes_on_past_a_type_that_fails(): void
    {
        // A type stored where its table does not exist: its records cannot be read.
        file_put_contents($this->dir . '/models/broken.json', (string) json_encode(
            ['key' => 'broken', 'table' => 'broken', 'storage' => 'nowhere', 'id_field' => 'uuid', 'publishing' => false,
                'frontend' => ['route' => '/broken/{slug}'], 'fields' => ['slug' => ['type' => 'string'], 'status' => ['type' => 'string']]],
        ));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $dm = new DataManager(
            drivers: [
                'json' => new JsonFileDriver($this->dir . '/store'),
                'default' => new JsonFileDriver($this->dir . '/internal'),
                'nowhere' => new \Preflow\Data\Driver\SqliteDriver($pdo),
            ],
            defaultDriver: 'json',
            typeRegistry: $registry,
        );
        $catalog = new TypeCatalog($this->dir . '/models');
        $resolver = FrontendResolver::fromCatalog($catalog, $dm);
        $publisher = FolioPublisher::make($dm, $catalog, new RecordValidator(new FieldTypeRegistry(), $dm));
        $warnings = [];
        $addresses = new RecordAddresses($dm, new RedirectEntries($dm), $resolver, new LiveRecords($dm, $publisher, $resolver), warn: static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);

        $lines = $addresses->seed();

        $this->assertSame('folio: recorded the public addresses of 1 record.', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('folio: addresses not recorded for broken: ', $lines[1]);
        $this->assertCount(1, $warnings);
        $this->assertSame(['en' => '/news/hello', 'de' => '/news/hello'], $addresses->of('post', 'n1'));
    }

    public function test_removal_target_parse(): void
    {
        $record = RemovalTarget::parse('page:p2', $this->resolver);
        $this->assertSame(['page', 'p2', null], [$record?->type, $record?->id, $record?->url]);
        $this->assertSame('https://example.org/x?y=1', RemovalTarget::parse(' https://example.org/x?y=1 ')?->url);
        $this->assertSame('/somewhere/else', RemovalTarget::parse('/somewhere/else')?->url);

        foreach (['', '  ', 'memo:m1', 'nope:x', 'page:', ':p1', 'page:a/b', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', 'ftp://example.org/', 'https://', 'relative/path', ['page:p1'], null, 42] as $bad) {
            $this->assertNull(RemovalTarget::parse($bad, $this->resolver), var_export($bad, true));
        }
    }

    /** Takes page $id (at /$slug) off the site with the posted target; its old address's entry target. */
    private function removeWith(string $id, string $slug, string $posted): string
    {
        $this->publishAndSync($id, ['en' => $slug]);
        $this->publisher->unpublish('page', $id);
        $this->addresses->removed('page', $id, RemovalTarget::parse($posted, $this->resolver));
        $entry = $this->entries->at('en', '/' . $slug);

        return $entry['target_url'] ?? ($entry['target_type'] !== null ? $entry['target_type'] . ':' . $entry['target_id'] : '-');
    }

    public function test_removal_url_target_of_a_page_becomes_the_record(): void
    {
        $this->publishAndSync('p2', ['en' => 'team', 'de' => 'team']);

        $this->assertSame('page:p2', $this->removeWith('p1', 'about', '/team'));
        $this->assertSame('page:p2', $this->removeWith('p3', 'contact', 'https://example.com/de/team'), 'an absolute URL of this site, another language');
        $this->assertSame('https://example.org/team', $this->removeWith('p4', 'jobs', 'https://example.org/team'), 'another host stays a URL');

        // The page moves: the old links follow it, no chain.
        $this->publishAndSync('p2', ['en' => 'crew', 'de' => 'team']);
        $this->assertSame('/crew', (new FolioRedirectTargets($this->live, $this->resolver))->url('page', 'p2', 'en'));
        $this->assertSame('page:p2', $this->entries->at('en', '/about')['target_type'] . ':' . $this->entries->at('en', '/about')['target_id']);
    }

    public function test_removal_url_target_that_redirects_takes_the_final_target(): void
    {
        $this->publishAndSync('p2', ['en' => 'team']);
        $this->publishAndSync('p2', ['en' => 'crew']);
        $this->entries->save(['path' => '/blog', 'locale' => 'en', 'area' => true, 'mode' => 'prefix', 'target_url' => 'https://example.org/journal']);
        $this->entries->save(['path' => '/ext', 'locale' => '', 'target_url' => '/elsewhere']);
        $this->entries->save(['path' => '/to-ext', 'locale' => 'en', 'target_url' => '/ext']);
        $this->entries->save(['path' => '/gone', 'locale' => 'en', 'kind' => 'removed', 'source_type' => 'page', 'source_id' => 'p9']);

        $this->assertSame('page:p2', $this->removeWith('p1', 'a', '/team'), 'an automatic entry: its record');
        $this->assertSame('https://example.org/journal/2024/x', $this->removeWith('p3', 'b', '/blog/2024/x'), 'a "keep the rest" area');
        $this->assertSame('/elsewhere', $this->removeWith('p4', 'c', '/to-ext'), 'through two entries');
        $this->assertSame('-', $this->removeWith('p5', 'd', '/gone'), 'an entry without a target: none');
    }

    public function test_removal_target_at_its_own_old_address_is_no_target(): void
    {
        $this->publishAndSync('p1', ['en' => 'a']);
        $this->publishAndSync('p1', ['en' => 'b']);
        $this->publisher->unpublish('page', 'p1');

        $this->addresses->removed('page', 'p1', RemovalTarget::parse('/b'));

        $this->assertSame(['en /a → - (removed, page:p1, 301)', 'en /b → - (removed, page:p1, 301)'], $this->entryLines());

        // Its earlier address (an entry to the record itself) is no target either.
        $this->assertSame('-', $this->removeWith('p3', 'c', '/a'));
    }

    public function test_removal_target_of_a_draft_or_unknown_record_is_no_target(): void
    {
        $this->working('page', 'd1', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'draft'], '_locales' => ['en']]);

        $this->assertSame('-', $this->removeWith('p1', 'a', 'page:d1'));
        $this->assertSame('-', $this->removeWith('p2', 'b', 'page:nope'));
    }

    public function test_coming_back_after_a_removal_with_target_takes_its_old_addresses_back(): void
    {
        $this->publishAndSync('overview', ['en' => 'overview']);
        $this->publishAndSync('p1', ['en' => 'a', 'de' => 'x']);
        $this->publishAndSync('p1', ['en' => 'b', 'de' => 'x']);
        $this->entries->save(['path' => '/promo', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1', 'kind' => 'manual']);
        $this->entries->save(['path' => '/hand', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'overview', 'kind' => 'manual']);
        $this->publisher->unpublish('page', 'p1');
        $this->addresses->removed('page', 'p1', RemovalTarget::parse('page:overview'));

        $this->publishAndSync('p1', ['en' => 'c', 'de' => 'x']);

        $this->assertSame([
            'en /a → page:p1 (auto, page:p1, 301)',
            'en /b → page:p1 (auto, page:p1, 301)',
            'en /hand → page:overview (manual, -, 301)',
            'en /promo → page:overview (manual, -, 301)',
        ], $this->entryLines(), 'hand-made entries keep the target they were given');
        $this->assertSame(['en' => '/c', 'de' => '/x'], $this->addresses->of('page', 'p1'));
    }
}
