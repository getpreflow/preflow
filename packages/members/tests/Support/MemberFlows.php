<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Data\DynamicRecord;
use Preflow\Members\Members;
use Preflow\Notify\Email;
use Preflow\Notify\TransportFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * Request flows through the member pages on a scaffold site: one app handles a whole flow
 * (the native session lives in $_SESSION, so these tests run in a separate process). The class
 * uses a scaffold with it: MembersScaffold (the core) or Folio's (Folio and rbac).
 */
trait MemberFlows
{

    private const string PASSWORD = 'correct horse battery';

    private string $remoteAddr = '203.0.113.7';

    /** The members path of the site (config/members.php path), for the CSRF warm-up request. */
    private string $membersPath = '/members';

    /** The flow site's config/members.php. @var array<string, mixed> */
    private array $flowMembers = [];

    /**
     * A site with a minimal page layout, config/members.php $members, migrated and applied.
     *
     * @param array<string, mixed> $members
     * @param list<string> $locales the first is the default
     * @param list<string> $sessionPaths
     * @param array<string, string> $files more site files (path => content), written before the migration
     */
    private function flowSite(array $members = [], array $locales = ['en', 'de'], ?array $sessionPaths = null, array $files = []): void
    {
        $this->membersPath = '/' . trim((string) ($members['path'] ?? '/members'), '/');
        $this->scaffold(locales: $locales, sessionPaths: $sessionPaths ?? ['/folio', $this->membersPath]);
        [$layoutFile, $layout] = $this->siteLayout();
        $this->flowMembers = $members + ($layout === null ? [] : ['layout' => $layout]);
        $this->write('config/members.php', "<?php\nreturn " . var_export($this->flowMembers, true) . ";\n");
        $this->write($layoutFile, <<<'TWIG'
<!DOCTYPE html>
<html lang="{{ locale }}"><head>{% block meta %}<title>Site</title>{% endblock %}</head>
<body><header>Site header</header><main>{% block content %}{% endblock %}</main></body></html>
TWIG);
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }
        $this->migrate();
        $this->flow = $this->app();
    }

    /** Changes config/members.php of the flow site ($more wins) and boots a fresh app. @param array<string, mixed> $more */
    private function reconfigure(array $more): void
    {
        $this->flowMembers = $more + $this->flowMembers;
        $this->write('config/members.php', "<?php\nreturn " . var_export($this->flowMembers, true) . ";\n");
        $this->flow = $this->app();
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $uri, array $body = [], array $cookies = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->remoteAddr]);
        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }

        return $this->flow->handle($body === [] ? $request : $request->withParsedBody($body));
    }

    /** POST with the session's CSRF token (a GET of a member page starts the session first). @param array<string, string> $body */
    private function post(string $uri, array $body): ResponseInterface
    {
        $this->send('GET', $this->membersPath . '/forgot');
        $token = (string) ($_SESSION['_csrf_token'] ?? '');

        return $this->send('POST', $uri, $body + ['_csrf_token' => $token]);
    }

    private function html(string $uri): string
    {
        return (string) $this->send('GET', $uri)->getBody();
    }

    /** @param string $state unconfirmed, pending (confirmed, waiting), member or rejected */
    private function makeMember(string $email = 'ada@example.com', string $state = 'member', string $lang = 'en'): DynamicRecord
    {
        $members = $this->flow->container()->get(Members::class);
        $m = $members->register($email, 'Ada Lovelace', self::PASSWORD, $lang);
        if ($state !== 'unconfirmed') {
            $members->confirm($m);
            $members->decide($m, $state);
        }

        return $m;
    }

    private function member(string $email): DynamicRecord
    {
        return $this->flow->container()->get(Members::class)->findByEmail($email) ?? $this->fail('No member ' . $email);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        $sent = $this->flow->container()->get(TransportFactory::class)->get('mail')->sent();

        return array_values(array_map(static fn ($s) => $s->message, $sent));
    }

    private function cookie(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }

        return null;
    }

    /** The value of a Set-Cookie line ("name=value; ..."). */
    private function cookieValue(ResponseInterface $response, string $name): string
    {
        preg_match('~^' . preg_quote($name, '~') . '=([^;]*)~', (string) $this->cookie($response, $name), $m);

        return $m[1] ?? '';
    }

    /** Like Application::run(): handle() leaves the queued mail alone until the test flushes it. */
    private function holdTheFlush(): void
    {
        (new \ReflectionProperty(Application::class, 'emitting'))->setValue($this->flow, true);
    }

    private function linkToken(int $mail, string $page): string
    {
        preg_match('~/' . $page . '/([A-Za-z0-9_-]+)~', $this->mails()[$mail]->text ?? '', $m);

        return $m[1] ?? $this->fail("No {$page} link in mail {$mail}");
    }

    /** The page's main markup without the CSRF token and the echoed address, which differ per request. */
    private function stripVolatile(string $html, string $email): string
    {
        $main = Dom::first($html, '//main');
        $this->assertNotNull($main);
        $inner = '';
        foreach ($main->childNodes as $child) {
            $inner .= $main->ownerDocument->saveHTML($child);
        }
        $inner = (string) preg_replace('~name="_csrf_token" value="[^"]*"~', 'name="_csrf_token" value=""', $inner);

        return str_replace([$email, htmlspecialchars($email)], '', $inner);
    }

    /** Switches the browser: the session of another one (an empty one for a new browser). @return array<string, mixed> the one left */
    private function useSession(array $session): array
    {
        $left = $_SESSION ?? [];
        $_SESSION = $session + ['_flash' => ['current' => [], 'previous' => []]];

        return $left;
    }
}
