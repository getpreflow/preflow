<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\I18n\Translator;
use Preflow\Routing\Router;
use Psr\Http\Message\ResponseInterface;

/**
 * Every admin screen in another language. A site with Folio, rbac (its staff type enabled),
 * members, codes and validation installed from their real composer.json walks every GET
 * route of its router under the admin prefix (Folio's own and every type the packages add:
 * lists, new and edit forms, settings, panels), signed in as a German and as a French user,
 * plus the signed-out pages. No page shows a raw key, and none shows an English text of the
 * packages' language files: every `en` text of 12 or more characters that differs from that
 * language's text (its literal parts between parameters, choice forms and tags).
 */
final class AllScreensTranslatedTest extends TestCase
{
    private const string ADMIN_EMAIL = 'admin@site.test';
    private const string PASSWORD = 'secret';
    /** A staff record (admin): its own account page exists, unlike the config user's. */
    private const string STAFF_EMAIL = 'ada@site.test';
    private const array PACKAGES = ['folio', 'rbac', 'members', 'codes', 'validation', 'i18n', 'media'];
    /** The groups whose texts an admin screen shows. */
    private const array GROUPS = ['folio', 'rbac', 'members-admin', 'codes', 'validation'];

    /**
     * Record data, not interface text: the roles list shows each role's stored label (rbac
     * translates a seeded role's label in the roles field while it is unchanged, but the
     * list's Label column is the record's value).
     */
    private const array DATA = ['/folio/rbac_role' => ['Administrator', 'Waiting for approval']];

    private string $dir = '';
    private string|false $errorLog = false;
    private ?Application $flow = null;
    /** @var list<string> every problem found, reported together */
    private array $problems = [];

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        ini_set('error_log', (string) $this->errorLog);
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return ['German' => ['de'], 'French' => ['fr']];
    }

    #[RunInSeparateProcess]
    #[DataProvider('locales')]
    public function test_every_admin_screen_speaks_the_users_language(string $locale): void
    {
        $this->site();
        $ids = $this->seed();
        $english = $this->englishTexts($locale);
        $this->assertGreaterThan(100, count($english), 'the English source list');

        // Signed out: the browser's language.
        foreach (['/folio/login', '/folio/forgot', '/folio/password/not-a-token'] as $uri) {
            $this->assertScreen($uri, $this->request('GET', $uri, [], ['Accept-Language' => $locale]), $locale, $english);
        }

        $this->signIn();
        $this->assertSame(302, $this->post('/folio/_locale', ['locale' => $locale])->getStatusCode());

        $walked = [];
        foreach ($this->adminUris($ids) as $uri) {
            $response = $this->request('GET', $uri);
            if ($this->assertScreen($uri, $response, $locale, $english)) {
                $walked[] = $uri;
            }
        }
        $this->assertSame([], array_values(array_unique($this->problems)), count($walked) . ' screens walked');
        // Every type's list and form, the dashboard, assets, extensions, cache, translations, account.
        foreach (['/folio', '/folio/assets', '/folio/extensions', '/folio/cache', '/folio/translations', '/folio/_account', '/folio/staff', '/folio/rbac_role', '/folio/member', '/folio/code', '/folio/post/new'] as $must) {
            $this->assertContains($must, $walked);
        }
        $this->assertGreaterThan(25, count($walked), implode("\n", $walked));
    }

    /** The checks on one page. @param list<string> $english @return bool whether it is an HTML page that was checked */
    private function assertScreen(string $uri, ResponseInterface $response, string $locale, array $english): bool
    {
        $status = $response->getStatusCode();
        if ($status >= 500) {
            $this->problems[] = "{$uri}: {$status} " . substr((string) $response->getBody(), 0, 300);

            return false;
        }
        if ($status >= 300 && $status < 400) {
            return false;
        }
        $html = (string) $response->getBody();
        $isPage = str_contains($response->getHeaderLine('Content-Type'), 'text/html') || str_starts_with(ltrim($html), '<');
        // The Translations screen lists keys and their English texts by design: its rows are left out, its frame is checked.
        $text = $this->readable(str_contains($html, 'folio-translations') ? (string) preg_replace('~(<table class="folio-table folio-translations">.*?<tbody>).*?(</tbody>)~s', '$1$2', $html) : $html);
        foreach (self::DATA[$uri] ?? [] as $data) {
            $text = str_replace($data, '', $text);
        }

        if (preg_match_all('~(?<![\w/-])(?:folio|rbac|members-admin|members|codes|validation)\.[a-z_]+\.[a-z0-9_.]*[a-z0-9_]~', $text, $keys) > 0) {
            foreach (array_unique($keys[0]) as $key) {
                $this->problems[] = "{$uri}: raw key {$key}";
            }
        }
        foreach ($english as $source) {
            if (str_contains($text, $source)) {
                $this->problems[] = "{$uri}: \"{$source}\" is still English";
            }
        }
        if ($isPage && str_contains($html, '<html') && !str_contains($html, '<html lang="' . $locale . '">')) {
            $this->problems[] = "{$uri}: not <html lang=\"{$locale}\">";
        }

        return $isPage && $status === 200;
    }

    /** What people read: entities decoded, code scripts left out, the JSON dictionary's texts in. */
    private function readable(string $html): string
    {
        $json = '';
        if (preg_match('~<script type="application/json" id="folio-i18n"[^>]*>(.*?)</script>~s', $html, $m) === 1) {
            $json = implode("\n", array_map('strval', (array) json_decode($m[1], true)));
        }
        $html = (string) preg_replace('~<script\b[^>]*>.*?</script>~s', '', $html);
        // An input's value is the record's data (a role's stored label), not interface text.
        $html = (string) preg_replace('~(<input\b[^>]*?\svalue=)"[^"]*"~', '$1""', $html);
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $decoded . "\n" . html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n" . $json;
    }

    /**
     * The English source list: literal parts (between :params, per choice form, without tags)
     * of 12 or more characters of every en text that differs from the $locale text, and that
     * the $locale text does not contain itself.
     *
     * @return list<string>
     */
    private function englishTexts(string $locale): array
    {
        $translator = $this->flow->container()->get(Translator::class);
        $list = [];
        foreach (self::GROUPS as $group) {
            $theirs = $translator->entries($group, $locale);
            foreach ($translator->entries($group, 'en') as $key => $en) {
                if (!is_string($en) || str_starts_with((string) $key, 'format.') || str_contains((string) $key, '.format.')) {
                    continue;
                }
                $other = is_string($theirs[$key] ?? null) ? $theirs[$key] : '';
                if ($other === $en) {
                    continue;
                }
                foreach (explode('|', strip_tags($en)) as $form) {
                    $form = (string) preg_replace('~^\s*(\{[^}]*\}|\[[^\]]*\])\s*~', '', $form);
                    foreach (preg_split('~:[a-z_]+~', $form) ?: [] as $part) {
                        $part = trim($part, " \t\n.,:;!?()\"'«»—–-");
                        if (mb_strlen($part) >= 12 && !str_contains($other, $part)) {
                            $list[$part] = true;
                        }
                    }
                }
            }
        }

        return array_keys($list);
    }

    /**
     * Every GET route of the router under /folio with its parameters filled: each type for
     * {type}, its seeded record for {id}, the seeded asset, a block key. Static files,
     * uploads, relation options (JSON) and exports (XLSX) are left out; so are the
     * signed-out pages (checked before signing in).
     *
     * @param array<string, string> $ids type → record id ('_asset' → asset id)
     * @return list<string>
     */
    private function adminUris(array $ids): array
    {
        $types = array_map(static fn ($l): string => $l->key, $this->flow->container()->get(TypeCatalog::class)->all());
        $uris = [];
        foreach ($this->router()->getCollection()->forMethod('GET') as $route) {
            $pattern = $route->pattern;
            if (!str_starts_with($pattern, '/folio')
                || preg_match('~/(_assets|_uploads|_relation|_export|login|forgot|password)\b~', $pattern) === 1) {
                continue;
            }
            if (str_contains($pattern, '{type}')) {
                foreach ($types as $type) {
                    if (str_contains($pattern, '{id}') && !isset($ids[$type])) {
                        continue;
                    }
                    $uris[] = strtr($pattern, ['{type}' => $type, '{id}' => $ids[$type] ?? '']);
                }
            } elseif (str_contains($pattern, '/assets/{id}')) {
                if (isset($ids['_asset'])) {
                    $uris[] = str_replace('{id}', $ids['_asset'], $pattern);
                }
            } elseif (str_contains($pattern, '{key}')) {
                $uris[] = str_replace('{key}', 'hero', $pattern) . '?name=sections';
            } else {
                $this->assertStringNotContainsString('{', $pattern, 'a GET route the walk does not know how to fill');
                $uris[] = $pattern;
            }
        }
        // The list views and states a route alone doesn't reach.
        $uris[] = '/folio/assets?view=list';
        $uris[] = '/folio/assets?picker=1&multiple=0';
        $uris[] = '/folio/post?q=nothing-matches';
        $uris[] = '/folio/post?kind=a&featured=1';
        $uris[] = '/folio/translations?q=nothing-matches';

        return array_values(array_unique($uris));
    }

    private function router(): Router
    {
        $router = $this->flow->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $this->assertInstanceOf(Router::class, $router);

        return $router;
    }

    private function site(): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/folio_all_screens_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'All screens', 'debug' => 0, 'engine' => 'twig', 'key' => 'all-screens-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, \\Preflow\\Members\\MembersServiceProvider::class, \\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn " . var_export(['path' => '/folio', 'blocks' => ['classes' => [
            \Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock::class,
            \Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock::class,
        ]]], true) . ";\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => [
                'session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff'],
                'members' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
            ],
            'providers' => [
                'staff' => ['class' => \Preflow\Folio\Users\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => self::ADMIN_EMAIL, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'roles' => ['admin']],
                ]],
                'users' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
                'members' => ['class' => \Preflow\Folio\Users\RecordUserProvider::class, 'type' => 'member', 'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles'],
            ],
            'session' => ['cookie' => 'all_screens_test', 'path' => '/', 'paths' => ['/folio', '/members'], 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
                'sqlite' => ['path' => $this->dir . '/storage/site.sqlite'],
            ],
            'aliases' => ['members' => 'sqlite', 'auth' => 'sqlite', 'throttle' => 'sqlite', 'codes' => 'sqlite'],
        ], true) . ";\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'fallback' => 'en', 'url_strategy' => 'prefix', 'cookie' => false];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'folio@site.test']]];\n");
        $this->write('config/codes.php', "<?php\nreturn ['purposes' => ['members.book' => ['label' => 'Book code', 'readable' => true]]];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string'], 'sections' => ['type' => 'blocks', 'allowed' => ['hero', 'link']]],
        ]));
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Posts', 'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'kind' => ['type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
                'featured' => ['type' => 'boolean'],
                'published_on' => ['type' => 'date'],
                'cover' => ['type' => 'asset'],
                'related' => ['type' => 'relation', 'to' => 'page'],
                'links' => ['type' => 'matrix', 'fields' => ['url' => ['type' => 'string']]],
                'body' => ['type' => 'richtext'],
            ],
            'list' => ['filters' => ['kind', 'featured'], 'bulk' => ['featured']],
        ]));
        $this->write('config/models/settings.json', (string) json_encode([
            'key' => 'settings', 'table' => 'settings', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Site', 'singleton' => true,
            'fields' => ['tagline' => ['type' => 'string']],
        ]));
        $packages = [];
        foreach (self::PACKAGES as $name) {
            $root = dirname(__DIR__, 3) . '/' . $name;
            $packages[] = [...json_decode((string) file_get_contents($root . '/composer.json'), true), 'install-path' => $root];
        }
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}, "rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}, "codes": {"disabled_parts": []}}}');
        $this->write('app/pages/index.twig', 'HOME');

        foreach ([new MigrateCommand(fn () => $this->app()), new ExtensionsApplyCommand(fn () => $this->app())] as $command) {
            ob_start();
            try {
                $code = $command->execute([]);
            } finally {
                $out = (string) ob_get_clean();
            }
            $this->assertSame(0, $code, $out);
        }
        $this->flow = $this->app();
    }

    /** One record of every type, a published post with a revision, an asset. @return array<string, string> type → id */
    private function seed(): array
    {
        $dm = $this->flow->container()->get(DataManager::class);
        $save = static function (string $type, array $data) use ($dm): void {
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), $data), false);
        };
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $save('page', ['uuid' => 'pg1', 'title' => 'Home', 'sections' => [['_uid' => 'b1', '_block' => 'hero', 'heading' => 'Hi']]]);
        $save('post', ['uuid' => 'po1', 'title' => 'First', 'kind' => 'a', 'featured' => true, 'related' => 'pg1', 'links' => [['url' => 'https://a.test']]]);
        $save('settings', ['uuid' => 'st1', 'tagline' => 'Tag']);
        $save('staff', ['uuid' => 'sf1', 'email' => 'ada@site.test', 'name' => 'Ada', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'roles' => 'admin', 'active' => true, 'auth_epoch' => '0']);
        $save('member', ['uuid' => 'mb1', 'email' => 'm@site.test', 'name' => 'Mia', 'password_hash' => '', 'language' => 'de', 'registered_at' => $now, 'email_confirmed_at' => $now, 'roles' => 'pending', 'auth_epoch' => '0']);
        $save('code', ['uuid' => 'cd1', 'purpose' => 'members.book', 'code' => 'ABCD-EFGH', 'label' => 'One', 'max_uses' => 1, 'uses' => 0]);
        $record = $dm->findType('post', 'po1');
        $record->set('title', 'First, published');
        $this->flow->container()->get(\Preflow\Folio\Publishing\Publisher::class)->publish($record, 'old@site.test');

        $ids = ['page' => 'pg1', 'post' => 'po1', 'settings' => 'st1', 'staff' => 'sf1', 'member' => 'mb1', 'code' => 'cd1'];
        $role = $dm->queryType('rbac_role')->first();
        if ($role instanceof DynamicRecord) {
            $ids['rbac_role'] = (string) $role->getId();
        }
        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(160, 120);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 10, 10));
            imagepng($image, $this->dir . '/red.png');
            $ids['_asset'] = $this->flow->container()->get(\Preflow\Media\MediaLibrary::class)->importFile($this->dir . '/red.png', 'red.png', null)->id;
        }

        return $ids;
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $body @param array<string, string> $headers */
    private function request(string $method, string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.7']);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->flow->handle($method === 'POST' ? $request->withParsedBody($body) : $request);
    }

    /** @param array<string, string> $body */
    private function post(string $uri, array $body): ResponseInterface
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $this->request('GET', '/folio/login');
        }

        return $this->request('POST', $uri, $body + ['_csrf_token' => (string) ($_SESSION['_csrf_token'] ?? '')]);
    }

    private function signIn(): void
    {
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => self::STAFF_EMAIL, 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }
}
