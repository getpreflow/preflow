<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members\Support;

use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Psr\Http\Message\ResponseInterface;

/**
 * The members core's scaffold site (packages/members/tests/Support/MembersScaffold, shared
 * rather than copied) with Folio, rbac and Folio's members admin (the built-in extension
 * "members") enabled: a fake vendor/composer/installed.json lists both packages, and a deploy
 * also runs folio:extensions:apply. The admin signs in at /folio.
 */
trait MembersScaffold
{
    use \Preflow\Members\Tests\Support\MembersScaffold {
        scaffold as private coreScaffold;
    }

    /**
     * @param array<string, string> $aliases
     * @param list<string> $locales
     * @param 'sqlite'|'mysql' $sql
     * @param list<string> $sessionPaths
     * @see \Preflow\Members\Tests\Support\MembersScaffold::scaffold()
     */
    private function scaffold(array $aliases = ['members' => 'sqlite', 'auth' => 'sqlite', 'throttle' => 'sqlite'], array $locales = ['en', 'de'], string $sql = 'sqlite', array $sessionPaths = ['/folio', '/members']): void
    {
        $this->coreScaffold($aliases, $locales, $sql, $sessionPaths);
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, \\Preflow\\Members\\MembersServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/rbac', 'extra' => ['folio' => ['extension' => 'Preflow\\Rbac\\Extension']]],
            ['name' => 'preflow/members'], // its admin is Folio's built-in extension "members"
        ]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}, "rbac": {"disabled_parts": []}}}');
    }

    /** On a Folio site the member pages use Folio's frontend layout, the default there. @return array{0: string, 1: ?string} */
    private function siteLayout(): array
    {
        return ['resources/folio/frontend/_site.twig', null];
    }

    /** As every deploy does: preflow migrate, then folio:extensions:apply. @return string the combined output */
    private function migrateAndApply(): string
    {
        $all = '';
        foreach ([new MigrateCommand(fn () => $this->app()), new ExtensionsApplyCommand(fn () => $this->app())] as $command) {
            [$code, $out] = $this->command($command);
            $this->assertSame(0, $code, $out);
            $all .= $out;
        }

        return $all;
    }

    private function migrate(): string
    {
        return $this->migrateAndApply();
    }

    /** Signs the admin in on the session app. */
    private function signIn(): void
    {
        $this->flow ??= $this->app();
        $login = (string) $this->flow->handle($this->request('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $login, $t);
        $response = $this->flow->handle($this->request('POST', '/folio/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD, '_csrf_token' => $t[1] ?? '']));
        $this->assertSame(302, $response->getStatusCode());
    }

    /** GET on the session app (signed in after signIn()). */
    private function admin(string $uri): string
    {
        return (string) ($this->flow ??= $this->app())->handle($this->request('GET', $uri))->getBody();
    }

    /** @param array<string, string> $body POSTed with the CSRF token read from $page */
    private function postFrom(string $page, string $uri, array $body): ResponseInterface
    {
        preg_match('/name="_csrf_token" value="([^"]+)"/', $this->admin($page), $t);

        return $this->flow->handle($this->request('POST', $uri, $body + ['_csrf_token' => $t[1] ?? '']));
    }
}
