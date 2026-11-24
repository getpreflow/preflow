<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Config\ConfigEdit;
use Preflow\DevTools\Config\ConfigFileEditor;

final class ConfigFileEditorTest extends TestCase
{
    private string $root;
    private string $configDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/preflow-config-editor-' . bin2hex(random_bytes(6));
        $this->configDir = $this->root . '/config';
        mkdir($this->configDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_creates_missing_file(): void
    {
        $contents = "<?php\n\nreturn [\n    'path' => '/folio',\n];\n";

        $edit = $this->editor()->create('folio.php', $contents);

        $this->assertSame(ConfigEdit::WRITTEN, $edit->status);
        $this->assertSame($this->configDir . '/folio.php', $edit->file);
        $this->assertSame($contents, file_get_contents($this->configDir . '/folio.php'));
        $this->assertSame(['path' => '/folio'], $this->editor()->read('folio.php'));
    }

    public function test_never_overwrites_existing_file(): void
    {
        $existing = "<?php\n\n// mine\nreturn ['path' => '/admin'];\n";
        file_put_contents($this->configDir . '/folio.php', $existing);

        $edit = $this->editor()->create('folio.php', "<?php\n\nreturn ['path' => '/folio'];\n");

        $this->assertSame(ConfigEdit::ALREADY, $edit->status);
        $this->assertSame($existing, file_get_contents($this->configDir . '/folio.php'));
    }

    public function test_adds_provider_at_start_keeps_comments(): void
    {
        $this->fixture('providers.php');

        $edit = $this->editor()->addToList('providers.php', ['\Preflow\Folio\FolioServiceProvider::class', '\Preflow\Rbac\RbacServiceProvider::class'], 'start');

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $this->assertSame(<<<'PHP'
            <?php

            // The app's providers, in boot order.
            return [ // first entry boots first
                \Preflow\Folio\FolioServiceProvider::class,
                \Preflow\Rbac\RbacServiceProvider::class,
                /* Mail before the app. */
                \App\Providers\MailServiceProvider::class,
                \App\Providers\AppServiceProvider::class, // the app's own, last

            PHP . "];\n", file_get_contents($this->configDir . '/providers.php'));
    }

    public function test_adds_provider_at_end(): void
    {
        file_put_contents($this->configDir . '/providers.php', "<?php\n\nreturn [\n    \\App\\Providers\\AppServiceProvider::class // own\n];\n");

        $edit = $this->editor()->addToList('providers.php', ['\Preflow\Folio\FolioServiceProvider::class'], 'end');

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $this->assertSame(
            "<?php\n\nreturn [\n    \\App\\Providers\\AppServiceProvider::class, // own\n    \\Preflow\\Folio\\FolioServiceProvider::class,\n];\n",
            file_get_contents($this->configDir . '/providers.php'),
        );
    }

    public function test_skips_item_already_present(): void
    {
        $this->fixture('providers.php');
        $before = file_get_contents($this->configDir . '/providers.php');

        // Written without the leading backslash: the same class by value.
        $edit = $this->editor()->addToList('providers.php', ['App\Providers\AppServiceProvider::class']);

        $this->assertSame(ConfigEdit::ALREADY, $edit->status);
        $this->assertSame($before, file_get_contents($this->configDir . '/providers.php'));

        $edit = $this->editor()->addToList('providers.php', ['App\Providers\AppServiceProvider::class', '\Preflow\Folio\FolioServiceProvider::class']);

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $this->assertSame([
            'Preflow\Folio\FolioServiceProvider',
            'App\Providers\MailServiceProvider',
            'App\Providers\AppServiceProvider',
        ], $this->editor()->read('providers.php'));
    }

    public function test_adds_guard_key_inside_guards(): void
    {
        $this->fixture('auth.php');
        $original = (string) file_get_contents($this->configDir . '/auth.php');

        $edit = $this->editor()->addKey('auth.php', ['guards'], 'staff', "[\n    'class' => Preflow\\Auth\\SessionGuard::class,\n    'provider' => 'staff',\n]");

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $expected = str_replace(
            "            'provider' => 'users',\n        ],\n    ],\n\n    'providers'",
            "            'provider' => 'users',\n        ],\n        'staff' => [\n            'class' => Preflow\\Auth\\SessionGuard::class,\n            'provider' => 'staff',\n        ],\n    ],\n\n    'providers'",
            $original,
        );
        $this->assertNotSame($original, $expected);
        $this->assertSame($expected, file_get_contents($this->configDir . '/auth.php'));

        $edit = $this->editor()->addKey('auth.php', [], 'api_guard', "'token'");
        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $config = $this->editor()->read('auth.php');
        $this->assertSame('token', $config['api_guard'] ?? null);
        $this->assertSame(['class' => 'Preflow\Auth\SessionGuard', 'provider' => 'staff'], $config['guards']['staff'] ?? null);
    }

    public function test_existing_key_is_not_changed(): void
    {
        $this->fixture('auth.php');
        $before = file_get_contents($this->configDir . '/auth.php');

        $edit = $this->editor()->addKey('auth.php', ['guards'], 'members', "['class' => 'Other']");

        $this->assertSame(ConfigEdit::ALREADY, $edit->status);
        $this->assertSame($before, file_get_contents($this->configDir . '/auth.php'));
    }

    public function test_adds_admin_path_to_session_paths(): void
    {
        $this->fixture('auth.php');
        $original = (string) file_get_contents($this->configDir . '/auth.php');

        $edit = $this->editor()->addToNestedList('auth.php', ['session', 'paths'], "'/folio'");

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $this->assertSame(
            str_replace("'paths' => ['/account'],", "'paths' => ['/account', '/folio'],", $original),
            file_get_contents($this->configDir . '/auth.php'),
        );

        $again = $this->editor()->addToNestedList('auth.php', ['session', 'paths'], "'/folio'");
        $this->assertSame(ConfigEdit::ALREADY, $again->status);
    }

    public function test_loads_the_apps_autoload_for_helpers(): void
    {
        mkdir($this->root . '/vendor');
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\nfunction config_editor_test_env(string \$k, mixed \$d = null): mixed { return \$d; }\n");
        file_put_contents($this->configDir . '/app.php', "<?php\n\nreturn [\n    'name' => config_editor_test_env('APP_NAME', 'Site'),\n];\n");

        $edit = $this->editor()->addKey('app.php', [], 'locale', "'en'");

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $this->assertSame(['name' => 'Site', 'locale' => 'en'], $this->editor()->read('app.php'));
    }

    /** @return iterable<string, array{string}> */
    public static function unrecognisedShapes(): iterable
    {
        yield 'array() syntax' => ['array-syntax.php'];
        yield 'computed return' => ['computed-return.php'];
        yield 'match' => ['match-return.php'];
        yield 'syntax error' => ['syntax-error.php'];
    }

    #[DataProvider('unrecognisedShapes')]
    public function test_unrecognised_shape_is_left_alone_and_reported(string $fixture): void
    {
        if ($fixture === 'syntax-error.php') {
            // Written here: the repository's parse guard rejects a .php file that does not parse.
            file_put_contents($this->configDir . '/providers.php', "<?php\n\nreturn [\n    \\App\\Providers\\AppServiceProvider::class\n    \\App\\Providers\\MailServiceProvider::class,\n];\n");
        } else {
            copy(__DIR__ . '/Fixtures/' . $fixture, $this->configDir . '/providers.php');
        }
        $before = file_get_contents($this->configDir . '/providers.php');

        $edit = $this->editor()->addToList('providers.php', ['\Preflow\Folio\FolioServiceProvider::class'], 'start');

        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertSame($before, file_get_contents($this->configDir . '/providers.php'));
        $this->assertStringContainsString('\Preflow\Folio\FolioServiceProvider::class,', (string) $edit->snippet);
        $this->assertNotSame('', $edit->message);
        $this->assertSame(['providers.php'], array_values(array_diff((array) scandir($this->configDir), ['.', '..'])), 'no temp file left behind');
    }

    public function test_refuses_when_the_path_is_not_an_array_literal(): void
    {
        file_put_contents($this->configDir . '/auth.php', "<?php\n\nreturn [\n    'guards' => array('members' => []),\n];\n");
        $before = file_get_contents($this->configDir . '/auth.php');

        $edit = $this->editor()->addKey('auth.php', ['guards'], 'staff', "['provider' => 'staff']");

        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertSame($before, file_get_contents($this->configDir . '/auth.php'));
        $this->assertSame("'staff' => ['provider' => 'staff'],", $edit->snippet);
    }

    public function test_refuses_and_restores_when_the_check_fails(): void
    {
        // The file is namespaced: an unqualified name resolves differently inside it than where the
        // editor evaluated it, so the written value differs from the intended one.
        file_put_contents($this->configDir . '/providers.php', "<?php\n\nnamespace App\\Config;\n\nreturn [\n    \\App\\Providers\\AppServiceProvider::class,\n];\n");
        $before = file_get_contents($this->configDir . '/providers.php');

        $edit = $this->editor()->addToList('providers.php', ['FolioServiceProvider::class'], 'start');

        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertSame($before, file_get_contents($this->configDir . '/providers.php'));
        $this->assertSame('FolioServiceProvider::class,', $edit->snippet);
    }

    public function test_symlinked_file_is_refused_and_left_a_link(): void
    {
        mkdir($this->root . '/shared');
        copy(__DIR__ . '/Fixtures/providers.php', $this->root . '/shared/providers.php');
        symlink($this->root . '/shared/providers.php', $this->configDir . '/providers.php');
        $before = file_get_contents($this->root . '/shared/providers.php');

        $edit = $this->editor()->addToList('providers.php', ['\Preflow\Folio\FolioServiceProvider::class'], 'start');

        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertStringContainsString('link', $edit->message);
        $this->assertSame('\Preflow\Folio\FolioServiceProvider::class,', $edit->snippet);
        $this->assertTrue(is_link($this->configDir . '/providers.php'));
        $this->assertSame($before, file_get_contents($this->root . '/shared/providers.php'));

        $edit = $this->editor()->addKey('providers.php', [], 'x', "'y'");
        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertTrue(is_link($this->configDir . '/providers.php'));
    }

    public function test_hard_linked_file_is_refused_and_both_names_unchanged(): void
    {
        $this->fixture('auth.php');
        link($this->configDir . '/auth.php', $this->root . '/auth-copy.php');
        $before = file_get_contents($this->configDir . '/auth.php');

        $edit = $this->editor()->addKey('auth.php', ['guards'], 'staff', "['provider' => 'staff']");

        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertStringContainsString('link', $edit->message);
        $this->assertSame($before, file_get_contents($this->configDir . '/auth.php'));
        $this->assertSame($before, file_get_contents($this->root . '/auth-copy.php'));
        $this->assertSame(2, (int) (stat($this->configDir . '/auth.php') ?: [])['nlink']);
    }

    public function test_create_refuses_a_dangling_symlink(): void
    {
        symlink($this->root . '/missing/folio.php', $this->configDir . '/folio.php');

        $edit = $this->editor()->create('folio.php', "<?php\n\nreturn [];\n");

        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertSame("<?php\n\nreturn [];\n", $edit->snippet);
        $this->assertTrue(is_link($this->configDir . '/folio.php'));
        $this->assertFileDoesNotExist($this->root . '/missing/folio.php');
    }

    public function test_much_output_on_stderr_does_not_hang_the_check(): void
    {
        file_put_contents($this->configDir . '/app.php', "<?php\n\nfwrite(STDERR, str_repeat('x', 200000));\n\nreturn [\n    'name' => 'Site',\n];\n");

        $edit = (new ConfigFileEditor($this->configDir, null, 5.0))->addKey('app.php', [], 'locale', "'en'");

        $this->assertSame(ConfigEdit::CHANGED, $edit->status, $edit->message);
        $this->assertSame(['name' => 'Site', 'locale' => 'en'], $this->editor()->read('app.php'));
    }

    public function test_check_past_the_deadline_refuses_and_restores(): void
    {
        // Sleeps only once the edit has added 'late' (the marker is split so the file does not
        // match before the edit): the check after writing runs past the deadline.
        file_put_contents($this->configDir . '/app.php', "<?php\n\nif (str_contains((string) file_get_contents(__FILE__), 'late' . \"' =>\")) {\n    sleep(5);\n}\n\nreturn [\n    'name' => 'Site',\n];\n");
        $before = file_get_contents($this->configDir . '/app.php');

        $started = microtime(true);
        $edit = (new ConfigFileEditor($this->configDir, null, 1.0))->addKey('app.php', [], 'late', 'true');

        $this->assertLessThan(4.0, microtime(true) - $started);
        $this->assertSame(ConfigEdit::REFUSED, $edit->status);
        $this->assertStringContainsString('did not finish within', $edit->message);
        $this->assertSame($before, file_get_contents($this->configDir . '/app.php'));
    }

    private function editor(): ConfigFileEditor
    {
        return new ConfigFileEditor($this->configDir);
    }

    private function fixture(string $name): void
    {
        copy(__DIR__ . '/Fixtures/' . $name, $this->configDir . '/' . $name);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
