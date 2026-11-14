<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\MediaLibrary;

/**
 * GET {prefix}/_search?q= (the ⌘K palette's records, SearchController): records of the types the
 * person may view ({type}.view) that the sidebar lists, at most 5 per type and 40 in all, then the
 * assets with assets.view. Any signed-in staff member reaches it; what they may not open is never in
 * the answer (Review Focus 1).
 */
final class SearchRouteTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('event', [
            'label' => 'Events',
            'fields' => ['name' => ['type' => 'string']],
        ]);
        $this->writeModel('hidden_thing', [
            'label' => 'Hidden things',
            'nav' => false,
            'fields' => ['name' => ['type' => 'string']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function seed(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Spring launch'], 'slug' => ['en' => 'spring']]);
        $this->working('post', 'p2', ['title' => ['en' => 'Autumn notes'], 'slug' => ['en' => 'autumn']]);
        $this->working('event', 'e1', ['name' => 'Spring fair']);
        $this->working('hidden_thing', 'h1', ['name' => 'Spring secret']);
        $this->working('site', 'site', ['tagline' => ['en' => 'Spring everywhere']]);
        $f = new Psr17Factory();
        $png = (function (): string {
            $image = imagecreatetruecolor(20, 10);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        })();
        $this->app()->container()->get(MediaLibrary::class)->upload(
            $f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'spring-meadow.png'),
            null,
        );
    }

    /** @return array{groups: list<array{type: string, label: string, family: ?string, icon: string, items: list<array{id: string, title: string, url: string}>}>} */
    private function search(string $q): array
    {
        $res = $this->session->handle((new Psr17Factory())->createServerRequest('GET', '/folio/_search?q=' . rawurlencode($q))->withHeader('Accept', 'application/json'));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));

        return json_decode((string) $res->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function types(array $answer): array
    {
        return array_column($answer['groups'], 'type');
    }

    #[RunInSeparateProcess]
    public function test_hits_only_for_viewable_types(): void
    {
        $this->seed();

        $this->signIn(['post.view']);
        $answer = $this->search('spring');
        $this->assertSame(['post'], self::types($answer), 'no events, no assets, no type outside the sidebar, no singleton');
        $this->assertSame([['id' => 'p1', 'title' => 'Spring launch', 'url' => '/folio/post/p1/edit']], $answer['groups'][0]['items']);
        $this->assertSame('Posts', $answer['groups'][0]['label']);
        $this->assertSame('page', $answer['groups'][0]['family']);
        $body = json_encode($answer);
        foreach (['Spring fair', 'Spring secret', 'spring-meadow', 'Spring everywhere'] as $other) {
            $this->assertStringNotContainsString($other, (string) $body, 'nothing the person cannot open');
        }
    }

    #[RunInSeparateProcess]
    public function test_every_viewable_type_then_the_assets(): void
    {
        $this->seed();
        $this->signIn(['event.view', 'post.view', 'assets.view', 'hidden_thing.view', 'site.view']);
        $answer = $this->search('Spring');

        $this->assertSame(['event', 'post', 'assets'], self::types($answer), 'records in sidebar order (one section, by name), assets last');
        $this->assertSame('Spring fair', $answer['groups'][0]['items'][0]['title']);
        $assets = $answer['groups'][2];
        $this->assertSame('Assets', $assets['label']);
        $this->assertSame('asset', $assets['family']);
        $this->assertCount(1, $assets['items']);
        $this->assertStringStartsWith('/folio/assets/', $assets['items'][0]['url']);
        $this->assertStringContainsString('spring-meadow', $assets['items'][0]['title']);
    }

    #[RunInSeparateProcess]
    public function test_a_short_term_finds_nothing_and_caps_hold(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->working('post', "p{$i}", ['title' => ['en' => "Spring {$i}"], 'slug' => ['en' => "s{$i}"]]);
        }
        $this->signIn(['post.view']);

        $this->assertSame(['groups' => []], $this->search('s'));
        $this->assertSame(['groups' => []], $this->search('  '));
        $this->assertCount(5, $this->search('spring')['groups'][0]['items'], 'at most 5 per type');
    }

    #[RunInSeparateProcess]
    public function test_any_signed_in_person_reaches_it(): void
    {
        $this->seed();
        $this->signIn([]);
        $this->assertSame(['groups' => []], $this->search('spring'), 'no permission: an empty answer, not a 403');
    }

    /** @param list<string> $permissions the one role's permissions */
    private function signIn(array $permissions): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_search_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => $permissions]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }
}
