<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;

/**
 * The public "members" group ("Sie"): the German registration page is byte for byte the one
 * of v0.36.0. (The admin's "members-admin" texts are tested with Folio's members admin.)
 */
final class RegisterSnapshotTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    /** The registration page and its error page in German, as v0.36.0 renders them (CSRF token blanked). */
    private const string SNAPSHOT = __DIR__ . '/Fixtures/register-de-v0.36.0.html';

    protected function tearDown(): void
    {
        if (($this->dir ?? '') !== '') {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_the_german_registration_page_is_the_one_of_v0_36_0(): void
    {
        $this->flowSite();

        $page = $this->send('GET', '/de/members/register');
        $errors = $this->post('/de/members/register', ['name' => '', 'email' => 'nope', 'password' => 'short']);
        $this->assertSame(200, $page->getStatusCode());
        $both = self::stable((string) $page->getBody()) . "\n<!-- errors -->\n" . self::stable((string) $errors->getBody());
        if (getenv('PREFLOW_WRITE_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, $both);
        }

        $this->assertStringContainsString('Bitte geben Sie', $both);
        $this->assertSame((string) file_get_contents(self::SNAPSHOT), $both);
    }

    private static function stable(string $html): string
    {
        return (string) preg_replace(['/(name="_csrf_token" value=")[^"]+/', '/(nonce=")[^"]+/', '/(Nonce":")[^"]+/'], '$1…', $html);
    }
}
