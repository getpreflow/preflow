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
use Preflow\Folio\Http\NavPreferenceController;
use Preflow\Folio\Users\UserPreferences;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Psr\Http\Message\ResponseInterface;

/** POST {prefix}/_prefs/nav saves one sidebar section as open or closed. */
final class NavPreferenceControllerTest extends TestCase
{
    private string $base;
    private UserPreferences $preferences;
    private NavPreferenceController $controller;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_navpref_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        $dm = new DataManager(['default' => new JsonFileDriver($this->base . '/s')], 'default', new TypeRegistry($this->base . '/m'), new ValidatorFactory(new RuleFactory()));
        $this->preferences = new UserPreferences($dm);
        $this->controller = new NavPreferenceController($this->preferences, '/folio');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, bool $signedIn = true): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://site.test/folio/_prefs/nav')->withParsedBody($body);
        if ($signedIn) {
            $request = $request->withAttribute(Authenticatable::class, new ArrayUser('u1', 'u@site.test', ''));
        }

        return $this->controller->update($request);
    }

    public function test_a_signed_in_user_saves_a_section(): void
    {
        $response = $this->post(['section' => 'settings', 'open' => '0']);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['settings' => false], $this->preferences->navSections('u1'));

        $this->assertSame(204, $this->post(['section' => 'settings', 'open' => '1'])->getStatusCode());
        $this->assertSame(['settings' => true], $this->preferences->navSections('u1'));
    }

    public function test_a_hyphenated_section_is_saved(): void
    {
        $this->assertSame(204, $this->post(['section' => 'member-area', 'open' => '0'])->getStatusCode());
        $this->assertSame(['member-area' => false], $this->preferences->navSections('u1'));
    }

    public function test_a_foreign_user_field_in_the_body_is_ignored(): void
    {
        $this->assertSame(204, $this->post(['section' => 'settings', 'open' => '0', 'user' => 'u2', 'id' => 'u2', 'auth_id' => 'u2'])->getStatusCode());

        $this->assertSame(['settings' => false], $this->preferences->navSections('u1'));
        $this->assertSame([], $this->preferences->navSections('u2'));
    }

    public function test_signed_out_is_sent_to_login_and_nothing_is_saved(): void
    {
        $response = $this->post(['section' => 'settings', 'open' => '0'], signedIn: false);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/login', $response->getHeaderLine('Location'));
        $this->assertSame([], $this->preferences->navSections('u1'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'path characters' => [['section' => '../x', 'open' => '1']];
        yield 'too long' => [['section' => str_repeat('a', 65), 'open' => '1']];
        yield 'leading digit' => [['section' => '2024', 'open' => '1']];
        yield 'leading hyphen' => [['section' => '-x', 'open' => '1']];
        yield 'empty' => [['section' => '', 'open' => '1']];
        yield 'upper case' => [['section' => 'Settings', 'open' => '1']];
        yield 'flag is a word' => [['section' => 'settings', 'open' => 'yes']];
        yield 'flag missing' => [['section' => 'settings']];
        yield 'section missing' => [['open' => '1']];
        yield 'section is an array' => [['section' => ['a'], 'open' => '1']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalid')]
    public function test_invalid_section_or_flag_is_refused(array $body): void
    {
        $this->assertSame(422, $this->post($body)->getStatusCode());
        $this->assertSame([], $this->preferences->navSections('u1'));
    }
}
