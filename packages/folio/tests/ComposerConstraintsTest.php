<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.83.0: blocks 0.83.0 (BlockLocale with localeOf(), which ContentLocale implements); media 0.83.0 (LocaleProvider
 * with localeOf(), which ContentLocaleProvider implements); i18n 0.83.0 (Machine\TranslatorAvailability and
 * TranslatorUnavailable, which Folio's machine translation uses).
 * Since 0.82.0: core 0.82.0 (Http\ContentSecurityPolicy, with which the whole-page preview reads the page's frame-ancestors).
 * Since 0.83.0: auth 0.83.0 (PermissionRegistry::disabled(), which the roles editor keeps disabled grants with).
 * Since 0.81.0: core 0.81.0 (Context\Writes, which ReviewIndexUpdater asks before it writes the review index, so a
 * preview or a view as a role leaves the index as it is); blocks 0.81.0 (ContextBlockLocale, which ContentLocale
 * implements); media 0.81.0 (ContextLocaleProvider, which ContentLocaleProvider implements).
 * Since 0.80.0: i18n 0.80.0 (Machine\AiPackage, the one check whether preflow/ai is installed, and MissingAiTranslator); ai is only
 * suggested (the AI features appear only when it is installed), and its conflict stays, so an installed ai is still 0.78 or newer.
 * Since 0.78.2: routing 0.78.2 (Redirect\LanguageRedirectedAddresses, which SiteTarget implements).
 * Since 0.78: core 0.78 (Http\LocaleUrls, which FrontendResolver builds every page URL with, "/en/…" with prefix_default; Routing\PageExists, under which Folio binds SiteTarget for the bare-path redirect); routing 0.78 (Redirect\PageExists extends core's PageExists, so SiteTarget answers under core's name); ai 0.78 (Limits\AiLimits and AiLimitReached, the per-person limits the draft and translate endpoints answer with); i18n 0.78 (JobRunner::step() throws AiLimitReached for a job over its person's limit); data 0.78 (TypeFieldDefinition's exact, which Folio's redirect, address, translation, extension and permission tables give their ids, keys and times).
 * Since 0.77.1: data 0.77.1 (MigrationRegistry::afterMigrate()'s sqlOnly, which folio.permissions and folio.addresses pass).
 * Since 0.77: core 0.77 (Activity\ActivityLog and ActivityActors, for the dashboard's recent changes and AuthorNames); data 0.77 (RecordTitle, which RecordLabeler uses); ai 0.77 (Draft\DraftWriter, behind "Write a draft"); i18n 0.77 (Machine\WritesAs, which FolioTextSource implements); media 0.77 (activity recording of asset changes); publishing 0.77 (Publisher::stateCounts() for the dashboard and status links, PersonalTypes, which MemberPersonalTypes implements); members 0.77 (raised in step with the release; members is only suggested, and records its activity through core's ActivityLog); devtools 0.77 (raised in step with the release; src calls no 0.77-only devtools API).
 *
 * Since 0.76: data 0.76 (Query::localize() with the type's locales, which the missing-alt filter relies on on JSON storage), i18n 0.76
 * (OverrideRules::placeholderParts(), the tokens of Settings → Translations) and media 0.76 (AssetUsage::counts(), AssetUsageEntry::$type,
 * MediaLibrary::query(missingAlt:), missingAltCount(), displayUrl()).
 * Since 0.75: core 0.75 (Version::label(), the installed Folio and core versions the user menu shows); data 0.75 (TypeFamily, the "family" TypeCatalog reads and the type tiles show) and i18n 0.75
 * (Completeness, the language indicator's three states, which TranslationStatus::state() computes); publishing 0.75
 * (RecordAddressFields, which FolioRecordTypes implements so RecordWriter::duplicate() frees a copy's address); blocks 0.75
 * (BlockDefinition::$family, which the block picker and cards read; blocks 0.74 has no such property); form 0.75 (FieldRenderer's
 * newline after <textarea>, which keeps a value's leading newline when FormOwner reparses the side panel).
 *
 * "^0.38 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing Folio with a release that lacks the APIs it calls: core 0.74 and view 0.74 (view's Audits, the SEO audit as a
 * service that Settings → SEO audit runs a step of, which core binds and renders in its internal render scope; since 0.70 core's CacheRegistry::renamedTo(), so a cache key Folio's blocks used to own still clears under its new name), routing 0.70 (Redirect\RedirectRules, RedirectRejected, RedirectUrl and the other redirect rule classes that moved out of Folio), i18n 0.71 (TextSources::add() with an "offered" predicate, so types with nothing to translate are not offered; since 0.70 OverrideRules, OverrideRefused, OverrideScope: the override checks that moved out of Folio), media 0.70 (alt texts as a text source, with a compare-and-set alt write; AltTextSource), rbac 0.69.1, ai 0.66 (AiStatus::$available and
 * $usesKey for Settings → AI, a provider of the app's own), i18n 0.68 (Labels::resolve() in a given locale, for
 * DisplayLabels::typeIn() naming a type in a mail's language; since 0.66 MachineTranslator::available(): Translate asks the
 * translator, not the AI connection), core 0.69.1 (Application::onProvidersBooted(), after which the console sets up the admin; since 0.68 it binds notify's Mailer with its layout and @notify templates, which StaffMailer
 * sends through; since 0.67 InstalledPackages keeps composer extra whole and ManifestBuilder reads extra.preflow.runtime, where Folio declares its extensions file; since 0.66 extra.folio.field_types
 * and blocks, which packages register with), publishing 0.81.0 (LiveContent reads the context's read mode; WorkingCopyMiddleware
 * and the share preview narrow it instead of calling showWorkingCopies()), i18n 0.81.0 (the translator follows the render
 * context's surface, which the admin request enters instead of setting a locale), auth 0.81.0 (SyntheticUser, the role view's user, which AdminAuthMiddleware refuses
 * with 403; since 0.69 AccountLinks, to which StaffPasswords leaves the links, the password
 * and the account lookups; since 0.65 AuthManager::provider() for a
 * staff guard that is not the default one, folio.guard; since 0.38 RecordUser and
 * RecordUserProvider moved to Preflow\Auth\Record, PasswordRehash; since 0.27 the chain
 * provider, ClaimsCredentials, SessionGuard::remembers()), core 0.65 (a non-Twig app engine is a SharingTemplateEngine and
 * TemplateEngineContext is bound, from which Folio builds its own Twig engine; since 0.61 the Application registers model classes as types, found with data's ModelClasses, so a class-backed type Folio lists is in the registry; since 0.60 the page cache: PageCache::isolate() and refuseWhen(), Refresher and DataTags for
 * Settings → Cache and publishing; since 0.59 Application::onRequest() and runningInConsole(), with which the admin is set up on demand; since 0.58 Composer\InstalledPackages and Config\ParsedJsonFiles,
 * which the extension registry, ModelExtension and TypeCatalog read through; since 0.52 NonPublicPaths, where Folio registers its admin; since
 * 0.50 it binds PublicPages, SeoSite and SeoContext, which FolioServiceProvider fills; since 0.34 Container::hasProvider(),
 * so media registers once; 0.33's cache registry Settings → Cache lists; since 0.27 it builds
 * the chain from config/auth.php, which RecordProviders and StaffPasswords look into), data
 * 0.68 (the TypeLabels interface, which DisplayTypeLabels implements and binds; since 0.61 findAs(), queryAs() and isReadOnly() for PublishedModels, TypeRegistry's class-backed types, ModelSchema; since 0.60 captureReads(), replayReads(), reportRead(), reportWrite(), QueryBuilder::unreported() and byIds(),
 * with which Folio's memos and frontend report to the page cache; since 0.42 ColumnKind, unique fields, RecordTables and typed record tables; since 0.41 DataManager::saveTypeIfVersion() for Publisher::saveDraft(); since 0.38 DeletedBy for deletePlan(); since 0.37 ModelLabelKeys and label maps; since 0.34 RecordTableSync, since 0.28 searchLoose, whereAny, deletePlan,
 * relations), i18n 0.47 (MachineServiceProvider for machine translation; since 0.39 Translator::alwaysOverride() for editable site texts; since 0.37 translation scopes, overrides, Labels, DerivedKeys, the fallback chain), notify 0.69
 * (MailText::siteName() for the staff mails; since 0.68 Mailer, MailContent, MailAction and MailText; since 0.25 Notifier::sendLater), routing 0.59 (RouteCollection::insertBefore(), which puts the admin routes in front of the frontend catch-all; since 0.52 the redirect store, targets and not-found log
 * interfaces Folio implements), media 0.50 (MediaLibrary::shareImage() for share cards; since 0.43 the bin: findBinned(), binned(), restoreFromBin(),
 * AssetUsageEntry::$blocksDelete; AssetCleanup for the "Unused only" filter; since 0.36 box presets, edits and
 * writeEdited, the asset classes Folio's library and field build on),
 * htmx 0.65 (HtmxDriver is a core HypermediaProtocol: Folio adds its own htmx to HypermediaProtocols),
 * twig 0.65 and view 0.65 (for view since 0.74, see above; Folio's own Twig engine: TwigEngine, LazyTemplateEngine, SharingTemplateEngine; since 0.54 the SEO audit's AuditReport, AuditRegistry::setTargets() and Target, which Settings → SEO audit
 * and AuditTargets use; since 0.50 PublicPages, PublicPage, SeoSite, SeoContext, PageMeta, the SEO head tags), validation 0.52 (the slug rule; since 0.46 the step rule, since 0.37 Message, ValidatorFactory::useTranslator()).
 * preflow/members is only suggested (Folio carries its admin when it is installed); a members
 * release before 0.68 lacks Members::accept(), decline(), canAccept(), canDecline() and DecisionRefused, which the
 * member admin calls, and still ships the members-admin texts Folio now carries
 * (before 0.39 also Members::lock(), unlock(), isLocked(), endSessions(), resendConfirmation()), so it conflicts.
 * preflow/codes is suggested too (Folio carries its admin since 0.40); a codes release before 0.68 lacks
 * Codes::checkUpdate(), update() with the checks in the write and CodeRefused, which the code admin calls, so it conflicts.
 * preflow/blocks (required since 0.70: the block classes, registry and renderer that were Folio's own; BlockLocale,
 * BlockTextCleaner and BlockPreviewMarks, which ContentLocale, SafeRichText and FolioBlockPreviewMarks implement)
 * conflicts below 0.75 (BlockDefinition::$family, see above).
 * preflow/publishing (required since 0.71: the Publisher, LiveContent and the other publishing classes that were Folio's own;
 * PublishingTypes, RecordChecks, LanguageChecks, RecordIndex and RecordAddresses, which Folio's adapters implement)
 * conflicts below 0.72: 0.71 still carries the config/folio.php fallback and the shadow-table index rules 0.72 changed.
 * preflow/rbac is suggested too (Folio carries its admin since 0.69); an rbac release before 0.81.0 does not declare
 * site.view_as (PreviewGate::VIEW_AS), which the whole-page preview's View as asks for (before 0.69 it still ships
 * that admin itself, its own extension "rbac", field types and texts, and lacks StaffAccounts, StaffLinks::available()
 * and StaffMail::sendTo(), which Folio's rbac admin and staff mails call; before 0.84.0 it lacks RoleStore::hasRoles(),
 * which tells Folio whether a site held roles before), so it conflicts.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/ai' => '<0.78', 'preflow/auth' => '<0.83.0', 'preflow/blocks' => '<0.83.0', 'preflow/codes' => '<0.68', 'preflow/core' => '<0.82.0', 'preflow/data' => '<0.84.0', 'preflow/devtools' => '<0.77', 'preflow/form' => '<0.75', 'preflow/htmx' => '<0.65', 'preflow/i18n' => '<0.83.0', 'preflow/media' => '<0.83.0', 'preflow/members' => '<0.77', 'preflow/notify' => '<0.69', 'preflow/publishing' => '<0.81.0', 'preflow/rbac' => '<0.84.0', 'preflow/routing' => '<0.78.2', 'preflow/twig' => '<0.65', 'preflow/validation' => '<0.52', 'preflow/view' => '<0.74'],
            $composer['conflict'] ?? null,
        );
        // Each conflict matches its require's lower bound; ai, members, codes and rbac are suggested, not required.
        foreach (['preflow/ai', 'preflow/members', 'preflow/codes', 'preflow/rbac'] as $suggested) {
            $this->assertArrayNotHasKey($suggested, $composer['require']);
            $this->assertArrayHasKey($suggested, $composer['suggest'] ?? []);
        }
        foreach (array_diff_key($composer['conflict'], ['preflow/ai' => true, 'preflow/members' => true, 'preflow/codes' => true, 'preflow/rbac' => true]) as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }

    public function test_declares_its_extensions_file_as_a_runtime_file(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertContains('config/folio/extensions.json', $composer['extra']['preflow']['runtime'] ?? []);
    }
}
