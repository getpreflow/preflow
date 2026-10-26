<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.27 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing Folio with a release that lacks the APIs it calls: auth 0.27 (chain
 * provider, ClaimsCredentials, SessionGuard::remembers()), core 0.34 (Container::hasProvider(),
 * so media registers once; 0.33's cache registry Settings → Cache lists; since 0.27 it builds
 * the chain from config/auth.php, which RecordProviders and StaffPasswords look into), data 0.37
 * (ModelLabelKeys and label maps; since 0.34 RecordTableSync, since 0.28 searchLoose, whereAny, deletePlan,
 * relations), i18n 0.37 (translation scopes, overrides, Labels, DerivedKeys, the fallback chain), notify 0.25
 * (Notifier::sendLater; the bound its require names), media 0.36 (box presets, edits and writeEdited; the asset
 * classes Folio's library and field build on), validation 0.37 (Message, ValidatorFactory::useTranslator()).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/auth' => '<0.27', 'preflow/core' => '<0.34', 'preflow/data' => '<0.37', 'preflow/i18n' => '<0.37', 'preflow/media' => '<0.36', 'preflow/notify' => '<0.25', 'preflow/validation' => '<0.37'],
            $composer['conflict'] ?? null,
        );
        // Each conflict matches its require's lower bound.
        foreach ($composer['conflict'] as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
