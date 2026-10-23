<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Migrator;

/**
 * Pins the exact SQLite DDL of every package migration, so the grammar refactor
 * (and later changes) can never alter what existing sites already have.
 */
final class SqliteDdlCharacterisationTest extends TestCase
{
    public function test_package_migrations_produce_unchanged_sqlite_ddl(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $paths = glob(dirname(__DIR__, 3) . '/*/migrations');
        $this->assertNotEmpty($paths);
        (new Migrator($pdo, $paths))->migrate();

        $actual = $pdo->query('SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame([
            ['type' => 'table', 'name' => 'auth_remember', 'sql' => 'CREATE TABLE "auth_remember" (
    "id" TEXT PRIMARY KEY,
    "guard" TEXT NOT NULL,
    "user_id" TEXT NOT NULL,
    "validator_hash" TEXT NOT NULL,
    "previous_hash" TEXT,
    "rotated_at" INTEGER,
    "expires_at" INTEGER NOT NULL
)'],
            ['type' => 'table', 'name' => 'auth_tokens', 'sql' => 'CREATE TABLE "auth_tokens" (
    "id" TEXT PRIMARY KEY,
    "purpose" TEXT NOT NULL,
    "subject" TEXT NOT NULL,
    "expires_at" INTEGER NOT NULL
)'],
            ['type' => 'table', 'name' => 'code_redemption', 'sql' => 'CREATE TABLE "code_redemption" (
    "id" TEXT PRIMARY KEY,
    "code_id" TEXT NOT NULL,
    "purpose" TEXT NOT NULL,
    "redeemer" TEXT,
    "redeemed_at" INTEGER NOT NULL
)'],
            ['type' => 'index', 'name' => 'idx_code_redemption_code_id', 'sql' => 'CREATE INDEX "idx_code_redemption_code_id" ON "code_redemption" ("code_id")'],
            ['type' => 'index', 'name' => 'idx_posts_slug', 'sql' => 'CREATE INDEX "idx_posts_slug" ON "posts" ("slug")'],
            ['type' => 'index', 'name' => 'idx_posts_title', 'sql' => 'CREATE INDEX "idx_posts_title" ON "posts" ("title")'],
            ['type' => 'index', 'name' => 'idx_user_tokens_tokenHash', 'sql' => 'CREATE INDEX "idx_user_tokens_tokenHash" ON "user_tokens" ("tokenHash")'],
            ['type' => 'index', 'name' => 'idx_users_email', 'sql' => 'CREATE INDEX "idx_users_email" ON "users" ("email")'],
            ['type' => 'table', 'name' => 'posts', 'sql' => 'CREATE TABLE "posts" (
    "uuid" TEXT PRIMARY KEY,
    "title" TEXT NOT NULL,
    "slug" TEXT NOT NULL,
    "body" TEXT,
    "status" TEXT NOT NULL,
    "created_at" TEXT,
    "updated_at" TEXT
)'],
            ['type' => 'table', 'name' => 'preflow_migrations', 'sql' => 'CREATE TABLE preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )'],
            ['type' => 'table', 'name' => 'preflow_throttle', 'sql' => 'CREATE TABLE "preflow_throttle" (
    "id" TEXT PRIMARY KEY,
    "purpose" TEXT NOT NULL,
    "hits" INTEGER NOT NULL,
    "window_start" INTEGER NOT NULL
)'],
            ['type' => 'table', 'name' => 'user_tokens', 'sql' => 'CREATE TABLE "user_tokens" (
    "uuid" TEXT PRIMARY KEY,
    "tokenHash" TEXT NOT NULL,
    "userId" TEXT NOT NULL,
    "name" TEXT,
    "createdAt" TEXT
)'],
            ['type' => 'table', 'name' => 'users', 'sql' => 'CREATE TABLE "users" (
    "uuid" TEXT PRIMARY KEY,
    "email" TEXT NOT NULL,
    "passwordHash" TEXT NOT NULL,
    "roles" TEXT,
    "createdAt" TEXT
)'],
        ], $actual);
    }
}
