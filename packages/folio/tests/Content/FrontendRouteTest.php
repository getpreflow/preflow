<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\FrontendRoute;

final class FrontendRouteTest extends TestCase
{
    public function test_permission_and_login_are_read(): void
    {
        $route = FrontendRoute::fromConfig('resource', [
            'route' => '/tools/r/{slug}',
            'permission' => 'members.access',
            'login' => ['en' => '/tools/login', 'de' => '/de/tools/login'],
        ]);

        $this->assertSame('members.access', $route->permission);
        $this->assertSame('/de/tools/login', $route->loginFor('de', 'en'));
        $this->assertSame('/tools/login', $route->loginFor('fr', 'en'));
        $this->assertNull(FrontendRoute::fromConfig('page', ['route' => '/{slug}'])->loginFor('en', 'en'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badLogins(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/login'];
        yield 'absolute url' => ['https://x.example/login'];
        yield 'backslash' => ['/\\x.example'];
        yield 'leading backslash' => ['\\\\x'];
        yield 'relative' => ['login'];
        yield 'empty string' => [''];
        yield 'empty map' => [[]];
        yield 'list (non-string locale key)' => [['/login']];
        yield 'non-string path in map' => [['en' => 5]];
        yield 'newline' => ["/login\nSet-Cookie: x=1"];
        yield 'tab' => ["/log\tin"];
        yield 'space' => ['/log in'];
        yield 'nul' => ["/login\0"];
        yield 'number' => [42];
    }

    #[DataProvider('badLogins')]
    public function test_login_must_be_a_path_on_this_site(mixed $login): void
    {
        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Folio type "resource": frontend.login');

        FrontendRoute::fromConfig('resource', ['route' => '/r/{slug}', 'permission' => 'members.access', 'login' => $login]);
    }
}
