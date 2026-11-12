<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.38 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing members with a release that lacks the APIs it calls: auth 0.65 (ListsRoles,
 * which MemberAccess implements; since 0.38 Preflow\Auth\Record\RecordUser(Provider), RoleField, PasswordRehash, Access::resolver()),
 * core 0.68 (binds notify's Mailer, which MemberMailer sends through; since 0.38 bootAuth binds RecordUserProvider::factory(),
 * so config/auth.php builds record users without Folio), notify 0.68 (Mailer, MailContent, MailAction, MailText), data 0.42 (unique fields for `email`, RecordTables; data 0.68's TypeLabels only when a package binds it, so no raise; since 0.38 DeletedBy and onSelfDelete in deletePlan(), ensureTypeRegistry()),
 * routing 0.59 (RoutePattern; since 0.59 Router::compiled()), i18n (raised with the release; since 0.37 translation scopes:
 * the public members group). Folio and rbac are suggestions only (the core runs without them); a Folio
 * before 0.68 lacks the members-admin texts members no longer ships (they moved to Folio) and decides past
 * Members::accept() / decline() (before 0.38 it still carried the old members admin), so it conflicts too.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/auth' => '<0.65', 'preflow/core' => '<0.68', 'preflow/data' => '<0.42', 'preflow/folio' => '<0.68', 'preflow/i18n' => '<0.38', 'preflow/notify' => '<0.68', 'preflow/routing' => '<0.59'],
            $composer['conflict'] ?? null,
        );
        foreach (['preflow/auth', 'preflow/core', 'preflow/data', 'preflow/i18n', 'preflow/notify', 'preflow/routing'] as $package) {
            $this->assertSame('^' . substr($composer['conflict'][$package], 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }

    public function test_needs_neither_folio_nor_rbac(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertArrayNotHasKey('preflow/folio', $composer['require']);
        $this->assertArrayNotHasKey('preflow/rbac', $composer['require']);
        $this->assertArrayHasKey('preflow/folio', $composer['suggest'] ?? []);
        $this->assertArrayHasKey('preflow/rbac', $composer['suggest'] ?? []);
    }
}
