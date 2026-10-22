<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Codes\Codes;
use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Migrator;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;

/**
 * A temp app with Folio and the codes extension enabled (a fake vendor/composer/installed.json
 * lists the package): default storage JSON, the codes alias on SQLite, two purposes.
 */
final class CodesTest extends TestCase
{
    private string $dir;
    private ?Application $app = null;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/codes_app_' . bin2hex(random_bytes(4));
        $this->now = new \DateTimeImmutable('2026-10-22 12:00:00');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Codes', 'debug' => 1, 'engine' => 'twig', 'key' => 'codes-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->dataConfig(['codes' => 'sqlite']);
        $this->write('config/codes.php', "<?php\nreturn " . var_export([
            'purposes' => [
                'members.book' => ['label' => 'Book code', 'readable' => true],
                'forcemap.round' => ['label' => 'Force Map round', 'readable' => false],
            ],
            'throttle' => ['max' => 10, 'window' => 900],
        ], true) . ";\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/codes', 'extra' => ['folio' => ['extension' => 'Preflow\\Codes\\Extension']]],
        ]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"codes": {"disabled_parts": []}}}');
        $this->write('app/pages/index.twig', 'HOME');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, string> $aliases */
    private function dataConfig(array $aliases): void
    {
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
                'sqlite' => ['path' => $this->dir . '/storage/codes.sqlite'],
            ],
            'aliases' => $aliases,
        ], true) . ";\n");
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** The booted app, migrated as `preflow migrate` does (package migrations, then Folio's table sync). */
    private function app(): Application
    {
        if ($this->app !== null) {
            return $this->app;
        }
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        $registry = $c->get(MigrationRegistry::class);
        $driver = $c->get(DataManager::class)->driver('codes');
        if ($driver instanceof PdoDriver) {
            (new Migrator($driver->pdo(), $registry->paths()))->migrate();
        }
        foreach ($registry->steps() as $step) {
            $step();
        }

        return $this->app = $app;
    }

    private function codes(): Codes
    {
        $c = $this->app()->container();

        return new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            require $this->dir . '/config/codes.php',
            fn (): \DateTimeImmutable => $this->now,
            $this->dir . '/storage/locks',
        );
    }

    public function test_issue_readable_and_redeem(): void
    {
        $codes = $this->codes();
        $code = $codes->issue('members.book', ['edition' => 1], 'SMARTSTACK2026', 'Book, first edition');

        $this->assertSame('SMARTSTACK2026', $code);
        $result = $codes->redeem('SMARTSTACK2026', 'members.book', 'member-1');
        $this->assertTrue($result->ok);
        $this->assertNull($result->reason);
        $this->assertSame(['edition' => 1], $result->payload);
        $this->assertNotNull($result->codeId);

        $record = $this->app()->container()->get(DataManager::class)->findType('code', $result->codeId);
        $this->assertSame(1, (int) $record->get('uses'));
        $this->assertSame('readable', $record->get('form'));
        $this->assertSame('Book, first edition', $record->get('label'));
    }

    public function test_readable_code_with_unicode_dashes_and_spaces(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'SMART-STACK 2026');

        foreach ([
            "SMART\u{2013}STACK 2026",            // en dash
            "SMART\u{2011}STACK\u{00A0}2026",     // non-breaking hyphen, non-breaking space
            "SMART\u{200B}STACK\u{2014}2026",     // zero-width space, em dash
            "\u{FEFF}smart stack\u{2009}2026\u{2060}", // BOM, thin space, word joiner
        ] as $typed) {
            $this->assertTrue($codes->check($typed, 'members.book')->ok, bin2hex($typed));
        }
        $this->assertSame('SMARTSTACK2026', Codes::normalizeReadable("SMART\u{2013}STACK\u{2011}\u{00A0}\u{200B}2026"));
    }

    public function test_readable_code_normalised(): void
    {
        $codes = $this->codes();
        $this->assertSame('SMARTSTACK2026', $codes->issue('members.book', [], ' smart-stack 2026 '));

        foreach (['smartstack2026', ' Smart-Stack 2026 ', "smart\tstack-2026\n", 'SMART-STACK-2026'] as $typed) {
            $this->assertTrue($codes->check($typed, 'members.book')->ok, $typed);
        }
        $this->assertSame('unknown', $codes->check('SMARTSTACK2027', 'members.book')->reason);

        // A random code pasted from a URL with surrounding whitespace matches, but stays case-sensitive.
        $random = $codes->issue('forcemap.round');
        $this->assertTrue($codes->check("  {$random}\n", 'forcemap.round')->ok);
        $this->assertSame('unknown', $codes->check(strtolower($random) === $random ? strtoupper($random) : strtolower($random), 'forcemap.round')->reason);
    }

    public function test_random_code_is_url_safe_and_128_bits(): void
    {
        $codes = $this->codes();
        $a = $codes->issue('forcemap.round');
        $b = $codes->issue('forcemap.round');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $a); // 16 bytes, base64url, no padding
        $this->assertSame(16, strlen((string) base64_decode(strtr($a, '-_', '+/'), true)));
        $this->assertNotSame($a, $b);
        $this->assertTrue($codes->check($a, 'forcemap.round')->ok);
    }

    public function test_wrong_purpose_is_unknown(): void
    {
        $codes = $this->codes();
        $round = $codes->issue('forcemap.round');
        $codes->issue('members.book', [], 'BOOK1234');

        $this->assertSame('unknown', $codes->check($round, 'members.book')->reason);
        $this->assertSame('unknown', $codes->redeem($round, 'members.book')->reason);
        $this->assertSame('unknown', $codes->check('BOOK1234', 'forcemap.round')->reason);
        $this->assertNull($codes->check($round, 'members.book')->codeId);
    }

    public function test_expired_used_up_revoked_reasons(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'EXPIRING', expiresAt: new \DateTimeImmutable('2026-10-22 13:00:00'));
        $codes->issue('members.book', [], 'TWICE', maxUses: 2);
        $codes->issue('members.book', [], 'REVOKED');

        $this->assertTrue($codes->check('EXPIRING', 'members.book')->ok);
        $this->now = new \DateTimeImmutable('2026-10-22 13:00:00');
        $this->assertSame('expired', $codes->check('EXPIRING', 'members.book')->reason);
        $this->assertSame('expired', $codes->redeem('EXPIRING', 'members.book')->reason);

        $this->assertTrue($codes->redeem('TWICE', 'members.book')->ok);
        $this->assertTrue($codes->redeem('TWICE', 'members.book')->ok);
        $usedUp = $codes->redeem('TWICE', 'members.book');
        $this->assertFalse($usedUp->ok);
        $this->assertSame('used_up', $usedUp->reason);
        $this->assertSame([], $usedUp->payload);

        $id = (string) $codes->check('REVOKED', 'members.book')->codeId;
        $codes->revoke($id);
        $this->assertSame('revoked', $codes->check('REVOKED', 'members.book')->reason);
        $this->assertSame('revoked', $codes->redeem('REVOKED', 'members.book')->reason);
        $this->assertSame([], $codes->redemptions($id));
    }

    public function test_empty_code_never_matches(): void
    {
        $codes = $this->codes();
        $dm = $this->app()->container()->get(DataManager::class);
        $type = $dm->getTypeRegistry()->get('code');
        foreach (['readable', 'random'] as $form) {
            foreach (['members.book', 'forcemap.round'] as $purpose) {
                $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($type, [
                    'uuid' => "empty-{$form}-{$purpose}", 'purpose' => $purpose, 'code' => '', 'form' => $form, 'uses' => 0, 'payload' => '{}',
                ]), false);
            }
        }

        foreach (['members.book', 'forcemap.round'] as $purpose) {
            foreach (['', '   ', '---', "\xff"] as $typed) {
                $this->assertSame('unknown', $codes->check($typed, $purpose)->reason, bin2hex($typed) . " / {$purpose}");
                $this->assertSame('unknown', $codes->redeem($typed, $purpose)->reason, bin2hex($typed) . " / {$purpose}");
            }
        }
    }

    public function test_check_does_not_count_use(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'ONCE', maxUses: 1);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($codes->check('ONCE', 'members.book')->ok);
        }
        $id = (string) $codes->check('ONCE', 'members.book')->codeId;
        $this->assertSame([], $codes->redemptions($id));
        $this->assertTrue($codes->redeem('ONCE', 'members.book')->ok);
        $this->assertSame('used_up', $codes->check('ONCE', 'members.book')->reason);
    }

    public function test_redemption_recorded_without_ip(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'BOOKCODE');
        $id = (string) $codes->redeem('BOOKCODE', 'members.book', 'member-7', 'client-hash')->codeId;
        $codes->redeem('BOOKCODE', 'members.book');

        $redemptions = $codes->redemptions($id);
        $this->assertCount(2, $redemptions);
        $this->assertSame(['id', 'redeemer', 'redeemed_at'], array_keys($redemptions[0]));
        $this->assertSame('member-7', $redemptions[0]['redeemer']);
        $this->assertSame($this->now->getTimestamp(), $redemptions[0]['redeemed_at']);
        $this->assertNull($redemptions[1]['redeemer']);

        $pdo = $this->app()->container()->get(DataManager::class)->driver('codes')->pdo();
        $columns = array_column($pdo->query('PRAGMA table_info(code_redemption)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['id', 'code_id', 'purpose', 'redeemer', 'redeemed_at'], $columns);
        $row = $pdo->query('SELECT * FROM code_redemption')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotContains('client-hash', array_values($row));
    }

    public function test_throttled_client_refused_even_with_valid_code(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'VALIDCODE');

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('unknown', $codes->redeem("WRONG{$i}", 'members.book', null, 'client-a')->reason);
        }
        $refused = $codes->redeem('VALIDCODE', 'members.book', 'member-1', 'client-a');
        $this->assertFalse($refused->ok);
        $this->assertSame('throttled', $refused->reason);
        $this->assertNull($refused->codeId);

        // Nothing was counted; another client (and another purpose) is not throttled.
        $this->assertTrue($codes->redeem('VALIDCODE', 'members.book', 'member-1', 'client-b')->ok);
        $id = (string) $codes->check('VALIDCODE', 'members.book')->codeId;
        $this->assertCount(1, $codes->redemptions($id));
        $round = $codes->issue('forcemap.round');
        $this->assertTrue($codes->redeem($round, 'forcemap.round', null, 'client-a')->ok);
    }

    public function test_no_client_not_throttled(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'VALIDCODE');

        for ($i = 0; $i < 15; $i++) {
            $this->assertSame('unknown', $codes->redeem("WRONG{$i}", 'members.book')->reason);
        }
        $this->assertTrue($codes->redeem('VALIDCODE', 'members.book')->ok);
    }

    public function test_check_with_client_is_throttled(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'VALIDCODE');

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('unknown', $codes->check("WRONG{$i}", 'members.book', 'client-a')->reason);
        }
        $refused = $codes->check('VALIDCODE', 'members.book', 'client-a');
        $this->assertFalse($refused->ok);
        $this->assertSame('throttled', $refused->reason);
        $this->assertNull($refused->codeId);

        // Another client is not throttled; the failures were shared with redeem()'s key.
        $this->assertTrue($codes->check('VALIDCODE', 'members.book', 'client-b')->ok);
        $this->assertSame('throttled', $codes->redeem('VALIDCODE', 'members.book', null, 'client-a')->reason);
    }

    public function test_check_without_client_not_throttled(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'VALIDCODE');

        for ($i = 0; $i < 15; $i++) {
            $this->assertSame('unknown', $codes->check("WRONG{$i}", 'members.book')->reason);
        }
        $this->assertTrue($codes->check('VALIDCODE', 'members.book')->ok);
    }

    public function test_concurrent_redeem_cannot_exceed_max_uses(): void
    {
        $c = $this->app()->container();
        $a = $this->codes();
        $b = new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            require $this->dir . '/config/codes.php',
            function () use (&$between): \DateTimeImmutable {
                if ($between !== null) {
                    [$run, $between] = [$between, null];
                    $run();
                }

                return $this->now;
            },
            $this->dir . '/storage/locks',
        );
        // An expiry makes check() read the clock, after it loaded the code and before it decides.
        $a->issue('members.book', [], 'ONLYONCE', maxUses: 1, expiresAt: new \DateTimeImmutable('2027-01-01'));

        // $b has passed check() with uses = 0 when $a redeems the last use.
        $first = null;
        $between = static function () use ($a, &$first): void { $first = $a->redeem('ONLYONCE', 'members.book', 'a'); };
        $second = $b->redeem('ONLYONCE', 'members.book', 'b');

        $this->assertTrue($first->ok);
        $this->assertFalse($second->ok);
        $this->assertSame('used_up', $second->reason);
        $id = (string) $first->codeId;
        $this->assertSame(1, (int) $this->app()->container()->get(DataManager::class)->findType('code', $id)->get('uses'));
        $this->assertCount(1, $a->redemptions($id));
    }

    public function test_json_storage_counts_under_file_lock(): void
    {
        $this->dataConfig(['codes' => 'json']);
        $codes = $this->codes();
        $codes->issue('members.book', [], 'ONLYONCE', maxUses: 1);

        $this->assertTrue($codes->redeem('ONLYONCE', 'members.book')->ok);
        $this->assertSame('used_up', $codes->redeem('ONLYONCE', 'members.book')->reason);
        $this->assertFileExists($this->dir . '/storage/locks');
    }

    public function test_max_uses_ten_allows_exactly_ten(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'TENTIMES', maxUses: 10);
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($codes->redeem('TENTIMES', 'members.book')->ok, "use {$i}");
        }
        $this->assertSame('used_up', $codes->redeem('TENTIMES', 'members.book')->reason);
    }

    public function test_uses_above_nine_compared_numerically(): void
    {
        $codes = $this->codes();
        $dm = $this->app()->container()->get(DataManager::class);
        foreach ([[12, true], [2, false]] as [$max, $ok]) {
            $codes->issue('members.book', [], "MAX{$max}CODE", maxUses: $max);
            $id = (string) $codes->check("MAX{$max}CODE", 'members.book')->codeId;
            $dm->execute("UPDATE code SET uses = '10' WHERE uuid = ?", [$id], 'codes');
            $result = $codes->redeem("MAX{$max}CODE", 'members.book');
            $this->assertSame($ok, $result->ok, "max {$max}");
            $this->assertSame($ok ? null : 'used_up', $result->reason);
        }
    }

    public function test_unlimited_code_counts_every_use(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'UNLIMITED');
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($codes->redeem('UNLIMITED', 'members.book')->ok);
        }
        $id = (string) $codes->check('UNLIMITED', 'members.book')->codeId;
        $this->assertSame(3, (int) $this->app()->container()->get(DataManager::class)->findType('code', $id)->get('uses'));
    }

    public function test_unknown_purpose_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown code purpose [shop.voucher]');
        $this->codes()->issue('shop.voucher');
    }

    public function test_readable_not_allowed_for_purpose_throws(): void
    {
        $codes = $this->codes();
        try {
            $codes->issue('forcemap.round', [], 'TEAMROUND');
            $this->fail('A readable code for a random-only purpose must throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('forcemap.round', $e->getMessage());
        }
        // Invalid readable codes and duplicates within a purpose throw too.
        foreach (['abc', str_repeat('A', 65), 'BOOK_CODE', 'BÜCHER'] as $bad) {
            try {
                $codes->issue('members.book', [], $bad);
                $this->fail("Readable code [{$bad}] must be rejected.");
            } catch (\InvalidArgumentException) {
            }
        }
        $codes->issue('members.book', [], 'BOOK1234');
        $this->expectException(\InvalidArgumentException::class);
        $codes->issue('members.book', [], 'book-1234');
    }

    public function test_missing_codes_alias_throws_at_boot(): void
    {
        $this->dataConfig([]);
        try {
            Application::create($this->dir)->boot();
            $this->fail('Booting with the codes extension and no codes alias must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'aliases' => ['codes' => '<driver>']", $e->getMessage());
        }

        // Without the extension enabled, nothing is required.
        $this->write('config/folio/extensions.json', '{"enabled": {}}');
        $app = Application::create($this->dir);
        $app->boot();
        $this->assertInstanceOf(Application::class, $app);
    }

    public function test_purpose_field_offers_configured_purposes(): void
    {
        $c = $this->app()->container();
        $field = $c->get(DataManager::class)->getTypeRegistry()->get('code')->fields['purpose'];
        $type = $c->get(\Preflow\Folio\Field\FieldTypeRegistry::class)->get($field->type);

        $this->assertSame('codes_purpose', $type->key());
        $this->assertSame(['in:members.book,forcemap.round'], $type->rules($field->config));
        $html = $type->renderEditor(new \Preflow\Folio\Field\FieldContext(name: 'purpose', label: 'Purpose', value: 'members.book', config: $field->config));
        $this->assertStringContainsString('Book code', $html);
        $this->assertStringContainsString('Force Map round', $html);
    }

    public function test_codes_and_redemptions_not_exported(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'BOOKCODE');
        $codes->redeem('BOOKCODE', 'members.book', 'member-1');

        $app = $this->app();
        $stream = fopen('php://temp', 'w+b');
        $summary = (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($stream);
        fclose($stream);

        $this->assertArrayHasKey('page', $summary['tables']);
        $this->assertArrayNotHasKey('code', $summary['tables']);
        $this->assertArrayNotHasKey('code_redemption', $summary['tables']);
        $this->assertSame(['page'], array_values(array_diff(array_keys($summary['tables']), ['folio_assets'])));
    }
}
