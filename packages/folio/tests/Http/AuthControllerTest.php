<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Folio\Http\AuthController;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AuthControllerTest extends TestCase
{
    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|action=' . ($context['login_action'] ?? '') . '|error=' . ($context['login_error'] ?? '');
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    /** @param GuardInterface $guard */
    private function authManager(GuardInterface $guard): AuthManager
    {
        return new AuthManager(['session' => $guard], 'session');
    }

    private function guard(bool $valid, bool &$loggedIn, ?\Preflow\Auth\Authenticatable $current = null): GuardInterface
    {
        return new class($valid, $loggedIn, $current) implements GuardInterface {
            public function __construct(private bool $valid, private bool &$loggedIn, private ?\Preflow\Auth\Authenticatable $current) {}
            public function user($request): ?\Preflow\Auth\Authenticatable { return $this->current; }
            public function validate(array $credentials): bool { return $this->valid; }
            public function login(\Preflow\Auth\Authenticatable $user, $request): void { $this->loggedIn = true; }
            public function logout($request): void {}
        };
    }

    private function provider(?ArrayUser $user): UserProviderInterface
    {
        return new class($user) implements UserProviderInterface {
            public function __construct(private ?ArrayUser $user) {}
            public function findById(string $id): ?\Preflow\Auth\Authenticatable { return $this->user; }
            public function findByCredentials(array $credentials): ?\Preflow\Auth\Authenticatable { return $this->user; }
        };
    }

    public function test_login_form_renders_with_action(): void
    {
        $loggedIn = false;
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn)), $this->provider(null), $this->engine(), '/folio');
        $res = $ctrl->loginForm((new Psr17Factory())->createServerRequest('GET', '/folio/login'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/login.twig', (string) $res->getBody());
        $this->assertStringContainsString('action=/folio/login', (string) $res->getBody());
    }

    public function test_login_form_redirects_when_already_authenticated(): void
    {
        $loggedIn = false;
        $current = new ArrayUser('a@b.test', 'a@b.test', 'H', []);
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn, $current)), $this->provider(null), $this->engine(), '/folio');
        $res = $ctrl->loginForm((new Psr17Factory())->createServerRequest('GET', '/folio/login'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio', $res->getHeaderLine('Location'));
    }

    public function test_login_success_logs_in_and_redirects_to_dashboard(): void
    {
        $loggedIn = false;
        $user = new ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
        $ctrl = new AuthController($this->authManager($this->guard(true, $loggedIn)), $this->provider($user), $this->engine(), '/folio');
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/login')
            ->withParsedBody(['email' => 'a@b.test', 'password' => 'secret']);
        $res = $ctrl->login($req);

        $this->assertTrue($loggedIn);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio', $res->getHeaderLine('Location'));
    }

    public function test_login_failure_flashes_and_redirects_to_login(): void
    {
        $loggedIn = false;
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn)), $this->provider(null), $this->engine(), '/folio');
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('flash')->with('error', $this->stringContains('Invalid'));
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/login')
            ->withParsedBody(['email' => 'a@b.test', 'password' => 'bad'])
            ->withAttribute(SessionInterface::class, $session);
        $res = $ctrl->login($req);

        $this->assertFalse($loggedIn);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    public function test_logout_redirects_to_login(): void
    {
        $loggedIn = false;
        $ctrl = new AuthController($this->authManager($this->guard(false, $loggedIn)), $this->provider(null), $this->engine(), '/folio');
        $res = $ctrl->logout((new Psr17Factory())->createServerRequest('POST', '/folio/logout'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }
}
