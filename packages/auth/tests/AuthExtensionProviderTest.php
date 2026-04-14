<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthExtensionProvider;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Testing\ArraySession;
use Preflow\View\TemplateFunctionDefinition;
use Psr\Http\Message\ServerRequestInterface;

final class AuthExtensionProviderTest extends TestCase
{
    private function makeGuard(?Authenticatable $user = null): GuardInterface
    {
        return new class($user) implements GuardInterface {
            public function __construct(private readonly ?Authenticatable $user) {}

            public function user(ServerRequestInterface $request): ?Authenticatable
            {
                return $this->user;
            }

            public function validate(array $credentials): bool
            {
                return false;
            }

            public function login(Authenticatable $user, ServerRequestInterface $request): void {}

            public function logout(ServerRequestInterface $request): void {}
        };
    }

    private function makeManager(?Authenticatable $user = null): AuthManager
    {
        return new AuthManager(['web' => $this->makeGuard($user)], 'web');
    }

    private function findFunction(array $functions, string $name): ?TemplateFunctionDefinition
    {
        foreach ($functions as $fn) {
            if ($fn->name === $name) {
                return $fn;
            }
        }
        return null;
    }

    public function test_auth_user_returns_null_when_not_set(): void
    {
        $manager = $this->makeManager();
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'auth_user');

        $this->assertNotNull($fn);
        $this->assertNull(($fn->callable)());
    }

    public function test_auth_user_returns_user_when_set(): void
    {
        $user = new TestUser();
        $manager = $this->makeManager();
        $manager->setUser($user);
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'auth_user');

        $this->assertNotNull($fn);
        $this->assertSame($user, ($fn->callable)());
    }

    public function test_auth_check_returns_boolean(): void
    {
        $manager = $this->makeManager();
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'auth_check');

        $this->assertNotNull($fn);
        $this->assertFalse(($fn->callable)());

        $manager->setUser(new TestUser());
        $this->assertTrue(($fn->callable)());
    }

    public function test_csrf_token_returns_raw_string_without_html(): void
    {
        $manager = $this->makeManager();
        $session = new ArraySession();
        $session->set('_csrf_token', 'abc123testtoken');

        $provider = new AuthExtensionProvider($manager, $session);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_token');

        $this->assertNotNull($fn);
        $result = ($fn->callable)();
        $this->assertSame('abc123testtoken', $result);
        $this->assertStringNotContainsString('<input', $result);
    }

    public function test_csrf_token_returns_empty_string_without_session(): void
    {
        $manager = $this->makeManager();
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_token');

        $this->assertNotNull($fn);
        $this->assertSame('', ($fn->callable)());
    }

    public function test_csrf_token_is_not_safe(): void
    {
        $manager = $this->makeManager();
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_token');

        $this->assertNotNull($fn);
        $this->assertFalse($fn->isSafe);
    }

    public function test_csrf_field_returns_html_input_with_csrf_token_name(): void
    {
        $manager = $this->makeManager();
        $session = new ArraySession();
        $session->set('_csrf_token', 'abc123testtoken');

        $provider = new AuthExtensionProvider($manager, $session);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_field');

        $this->assertNotNull($fn);
        $result = ($fn->callable)();
        $this->assertStringContainsString('<input', $result);
        $this->assertStringContainsString('name="_csrf_token"', $result);
        $this->assertStringContainsString('value="abc123testtoken"', $result);
        $this->assertStringContainsString('type="hidden"', $result);
    }

    public function test_csrf_field_returns_empty_string_without_session(): void
    {
        $manager = $this->makeManager();
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_field');

        $this->assertNotNull($fn);
        $this->assertSame('', ($fn->callable)());
    }

    public function test_csrf_field_is_safe(): void
    {
        $manager = $this->makeManager();
        $provider = new AuthExtensionProvider($manager);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_field');

        $this->assertNotNull($fn);
        $this->assertTrue($fn->isSafe);
    }

    public function test_csrf_field_escapes_special_characters(): void
    {
        $manager = $this->makeManager();
        $session = new ArraySession();
        $session->set('_csrf_token', 'token<with>"special&chars');

        $provider = new AuthExtensionProvider($manager, $session);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'csrf_field');

        $this->assertNotNull($fn);
        $result = ($fn->callable)();
        $this->assertStringContainsString('value="token&lt;with&gt;&quot;special&amp;chars"', $result);
    }

    public function test_flash_reads_from_session(): void
    {
        $manager = $this->makeManager();
        $session = new ArraySession();
        $session->flash('error', 'Something went wrong');

        $provider = new AuthExtensionProvider($manager, $session);

        $functions = $provider->getTemplateFunctions();
        $fn = $this->findFunction($functions, 'flash');

        $this->assertNotNull($fn);
        $this->assertSame('Something went wrong', ($fn->callable)('error'));
        $this->assertNull(($fn->callable)('missing'));
    }
}
