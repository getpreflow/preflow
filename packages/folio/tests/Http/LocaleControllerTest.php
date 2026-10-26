<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\Authenticatable;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Http\InterfaceLocales;
use Preflow\Folio\Http\LocaleController;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/** POST {prefix}/_locale goes back to the admin page it came from, never off the site. */
final class LocaleControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_locale_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function location(string $prefix, string $referer): string
    {
        $translator = new Translator($this->base . '/lang', 'en', 'en');
        $translator->addDefaults('folio', dirname(__DIR__, 2) . '/lang');
        $dm = new DataManager(['default' => new JsonFileDriver($this->base . '/s')], 'default', new TypeRegistry($this->base . '/m'), new ValidatorFactory(new RuleFactory()));
        $controller = new LocaleController(new UserPreferences($dm), new InterfaceLocales($translator, 'en'), $translator, $prefix);
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://site.test' . rtrim($prefix, '/') . '/_locale')
            ->withHeader('Referer', $referer)
            ->withParsedBody(['locale' => 'de'])
            ->withAttribute(Authenticatable::class, new ArrayUser('u1', 'u@site.test', ''));
        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());

        return $response->getHeaderLine('Location');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function referers(): iterable
    {
        yield 'admin page' => ['/folio', 'https://site.test/folio/post?page=2', '/folio/post?page=2'];
        yield 'dashboard' => ['/folio', 'https://site.test/folio', '/folio'];
        yield 'outside the admin' => ['/folio', 'https://site.test/blog', '/folio'];
        yield 'other host' => ['/folio', 'https://evil.example/folio/post', '/folio'];
        yield 'protocol-relative path' => ['/folio', 'https://site.test//evil.example/folio/x', '/folio'];
        yield 'root prefix: admin page' => ['/', 'https://site.test/post/1/edit', '/post/1/edit'];
        yield 'root prefix: dashboard' => ['/', 'https://site.test/', '/'];
        yield 'root prefix: protocol-relative path' => ['/', 'https://site.test//evil.example/x', '/'];
        yield 'empty prefix: admin page' => ['', 'https://site.test/post', '/post'];
        yield 'empty prefix: protocol-relative path' => ['', 'https://site.test//evil.example/x', '/'];
        yield 'root prefix: backslash' => ['/', 'https://site.test/\\evil.example', '/'];
    }

    #[DataProvider('referers')]
    public function test_goes_back_to_the_referring_admin_page_only(string $prefix, string $referer, string $expected): void
    {
        $this->assertSame($expected, $this->location($prefix, $referer));
    }

    public function test_a_signed_out_request_goes_to_the_login_page_also_with_the_admin_at_the_root(): void
    {
        $translator = new Translator($this->base . '/lang', 'en', 'en');
        $dm = new DataManager(['default' => new JsonFileDriver($this->base . '/s')], 'default', new TypeRegistry($this->base . '/m'), new ValidatorFactory(new RuleFactory()));
        foreach (['/' => '/login', '' => '/login', '/folio' => '/folio/login'] as $prefix => $expected) {
            $controller = new LocaleController(new UserPreferences($dm), new InterfaceLocales($translator, 'en'), $translator, $prefix);
            $response = $controller->update((new Psr17Factory())->createServerRequest('POST', 'https://site.test/_locale'));
            $this->assertSame($expected, $response->getHeaderLine('Location'), (string) $prefix);
        }
    }
}
