<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.38 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing Folio with a release that lacks the APIs it calls: ai 0.66 (AiStatus::$available and
 * $usesKey for Settings → AI, a provider of the app's own), core 0.66 (InstalledPackages keeps extra.folio.field_types
 * and blocks, which packages register with), auth 0.65 (AuthManager::provider() for a
 * staff guard that is not the default one, folio.guard; since 0.38 RecordUser and
 * RecordUserProvider moved to Preflow\Auth\Record, PasswordRehash; since 0.27 the chain
 * provider, ClaimsCredentials, SessionGuard::remembers()), core 0.65 (a non-Twig app engine is a SharingTemplateEngine and
 * TemplateEngineContext is bound, from which Folio builds its own Twig engine; since 0.61 the Application registers model classes as types, found with data's ModelClasses, so a class-backed type Folio lists is in the registry; since 0.60 the page cache: PageCache::isolate() and refuseWhen(), Refresher and DataTags for
 * Settings → Cache and publishing; since 0.59 Application::onRequest() and runningInConsole(), with which the admin is set up on demand; since 0.58 Composer\InstalledPackages and Config\ParsedJsonFiles,
 * which the extension registry, ModelExtension and TypeCatalog read through; since 0.52 NonPublicPaths, where Folio registers its admin; since
 * 0.50 it binds PublicPages, SeoSite and SeoContext, which FolioServiceProvider fills; since 0.34 Container::hasProvider(),
 * so media registers once; 0.33's cache registry Settings → Cache lists; since 0.27 it builds
 * the chain from config/auth.php, which RecordProviders and StaffPasswords look into), data
 * 0.61 (findAs(), queryAs() and isReadOnly() for PublishedModels, TypeRegistry's class-backed types, ModelSchema; since 0.60 captureReads(), replayReads(), reportRead(), reportWrite(), QueryBuilder::unreported() and byIds(),
 * with which Folio's memos and frontend report to the page cache; since 0.42 ColumnKind, unique fields, RecordTables and typed record tables; since 0.41 DataManager::saveTypeIfVersion() for Publisher::saveDraft(); since 0.38 DeletedBy for deletePlan(); since 0.37 ModelLabelKeys and label maps; since 0.34 RecordTableSync, since 0.28 searchLoose, whereAny, deletePlan,
 * relations), i18n 0.47 (MachineServiceProvider for machine translation; since 0.39 Translator::alwaysOverride() for editable site texts; since 0.37 translation scopes, overrides, Labels, DerivedKeys, the fallback chain), notify 0.25
 * (Notifier::sendLater; the bound its require names), routing 0.59 (RouteCollection::insertBefore(), which puts the admin routes in front of the frontend catch-all; since 0.52 the redirect store, targets and not-found log
 * interfaces Folio implements), media 0.50 (MediaLibrary::shareImage() for share cards; since 0.43 the bin: findBinned(), binned(), restoreFromBin(),
 * AssetUsageEntry::$blocksDelete; AssetCleanup for the "Unused only" filter; since 0.36 box presets, edits and
 * writeEdited, the asset classes Folio's library and field build on),
 * htmx 0.65 (HtmxDriver is a core HypermediaProtocol: Folio adds its own htmx to HypermediaProtocols),
 * twig 0.65 and view 0.65 (Folio's own Twig engine: TwigEngine, LazyTemplateEngine, SharingTemplateEngine; since 0.54 the SEO audit's AuditReport, AuditRegistry::setTargets() and Target, which Settings → SEO audit
 * and AuditTargets use; since 0.50 PublicPages, PublicPage, SeoSite, SeoContext, PageMeta, the SEO head tags), validation 0.52 (the slug rule; since 0.46 the step rule, since 0.37 Message, ValidatorFactory::useTranslator()).
 * preflow/members is only suggested (Folio carries its admin when it is installed); a members
 * release before 0.39 lacks Members::lock(), unlock(), isLocked(), endSessions() and resendConfirmation()
 * (and before 0.38 still shipped its own admin extension), so it conflicts. preflow/codes is suggested too
 * (Folio carries its admin since 0.40; before that the package shipped it), so it conflicts below 0.40.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/ai' => '<0.66', 'preflow/auth' => '<0.65', 'preflow/codes' => '<0.40', 'preflow/core' => '<0.66', 'preflow/data' => '<0.61', 'preflow/htmx' => '<0.65', 'preflow/i18n' => '<0.47', 'preflow/media' => '<0.50', 'preflow/members' => '<0.39', 'preflow/notify' => '<0.25', 'preflow/routing' => '<0.59', 'preflow/twig' => '<0.65', 'preflow/validation' => '<0.52', 'preflow/view' => '<0.65'],
            $composer['conflict'] ?? null,
        );
        // Each conflict matches its require's lower bound; members and codes are suggested, not required.
        foreach (['preflow/members', 'preflow/codes'] as $suggested) {
            $this->assertArrayNotHasKey($suggested, $composer['require']);
            $this->assertArrayHasKey($suggested, $composer['suggest'] ?? []);
        }
        foreach (array_diff_key($composer['conflict'], ['preflow/members' => true, 'preflow/codes' => true]) as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
