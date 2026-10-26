<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\DefaultResolver;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Core\Application;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\Exceptions\ContainerException;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\I18n\Translator;
use Preflow\I18n\TranslationExtensionProvider;
use Preflow\View\AssetCollector;
use Preflow\Members\Access\MemberAccess;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Http\MembersRoutes;
use Preflow\Members\Http\SafeNext;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Routing\Router;
use Preflow\Notify\Notifier;
use Preflow\View\TemplateEngineInterface;

/**
 * Binds the members services: MembersConfig (config/members.php; a missing file means the
 * defaults), MemberEvents (the hooks), Members, Confirmation, SignIn, Links, LinkValidity, MemberMailer, the configured Admission and the
 * owner's settings (ConfigMembersSettings, unless a package such as Folio's members admin binds
 * its own), and adds the package copy (lang/{locale}/members.php) under the site's own. A site's
 * resources/members/ is put in front of the package templates (@members) when it exists. Boot
 * checks that the admission class is one, defines the member type when nothing has
 * (MemberType), lets admitted members have members.access (declared at register) when no permission
 * package decides (MemberAccess) and adds the member pages' routes under the members path.
 * Neither Folio nor rbac is needed; with them, Folio carries the admin and rbac decides access.
 */
final class MembersServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        self::siteTemplates($container);
        // The core's one permission, before any provider boots (Folio's members admin, which
        // declares it too, takes the core's; its own members.decide stays the admin's).
        if ($container->bound(PermissionRegistry::class) && !$container->get(PermissionRegistry::class)->isDeclared(MembersConfig::PERMISSION)) {
            $container->get(PermissionRegistry::class)->declare(MembersConfig::PERMISSION, 'Use the members area', 'Members');
        }
        // Declared in composer.json (extra.preflow.translations); this covers apps whose Composer
        // metadata doesn't list the package (test apps, a core before 0.37).
        self::translator($container)->addDefaultsIfMissing('members', dirname(__DIR__) . '/lang', 'public');
        self::translator($container)->addDefaultsIfMissing('members-admin', dirname(__DIR__) . '/lang', 'admin');
        $container->singleton(MembersConfig::class, static fn (Container $c): MembersConfig => MembersConfig::fromFile(
            $c->get(Application::class)->basePath('config/members.php'),
            $c->bound(MembersDefaults::class) ? $c->get(MembersDefaults::class)->config : [],
        ));
        // Folio's members admin binds its own (Settings → Members), whichever provider registers first.
        if (!$container->bound(MembersSettings::class)) {
            $container->singleton(MembersSettings::class, static fn (Container $c): MembersSettings => new ConfigMembersSettings($c->get(MembersConfig::class)));
        }
        // The hooks: one registry; projects add listeners in their provider's boot().
        $container->singleton(MemberEvents::class, static fn (): MemberEvents => new MemberEvents());
        $container->singleton(Members::class, static fn (Container $c): Members => new Members(
            $c->get(DataManager::class),
            $c->get(PasswordHasherInterface::class),
            null,
            static fn (): Confirmation => $c->get(Confirmation::class),
            $c->get(MemberEvents::class),
            static fn (DynamicRecord $m, DeletePlan $plan, string $reason): bool => self::refusalNotice($c, $m, $plan, $reason),
        ));
        $container->singleton(Confirmation::class, static fn (Container $c): Confirmation => new Confirmation(
            $c->get(Members::class),
            $c->get(Admission::class),
            $c->get(MemberMailer::class),
            $c->get(MembersSettings::class),
            $c->get(MemberEvents::class),
        ));
        $container->singleton(SignIn::class, static fn (Container $c): SignIn => new SignIn($c->get(AuthManager::class), $c->get(MemberEvents::class)));
        $container->singleton(LinkValidity::class, static fn (Container $c): LinkValidity => new LinkValidity(
            $c->get(Translator::class),
            $c->get(MembersConfig::class),
        ));
        $container->singleton(Links::class, static fn (Container $c): Links => self::links($c));
        $container->singleton(SafeNext::class, static fn (Container $c): SafeNext => new SafeNext($c->get(MembersConfig::class), $c->get(Links::class)));
        $container->singleton(MemberMailer::class, static fn (Container $c): MemberMailer => new MemberMailer(
            $c->get(Notifier::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(Translator::class),
            $c->get(Links::class),
            $c->get(Locales::class),
            $c->get(MembersConfig::class),
            $c->get(Admission::class),
            $c->get(LinkValidity::class),
            self::from($c),
            $c->bound(MemberLinks::class) ? $c->get(MemberLinks::class) : null,
        ));
        $container->singleton(MembersView::class, static fn (Container $c): MembersView => new MembersView(
            $c->get(TemplateEngineInterface::class),
            $c->get(AssetCollector::class),
            $c->get(Viewer::class),
            $c->get(MembersConfig::class),
            $c->get(Links::class),
            $c->get(LinkValidity::class),
            $c->get(Locales::class),
            $c->get(Translator::class),
            $c->bound(PageLocale::class) ? $c->get(PageLocale::class) : null,
        ));
        $container->singleton(Admission::class, static function (Container $c): Admission {
            $class = $c->get(MembersConfig::class)->admission();
            if (!class_exists($class) || !is_subclass_of($class, Admission::class)) {
                throw new \RuntimeException("config/members.php admission: {$class} does not implement " . Admission::class . '.');
            }

            return $c->get($class);
        });
    }

    public function boot(Container $container): void
    {
        $container->get(Admission::class);
        MemberType::define($container);
        self::access($container);
        self::routes($container);
    }

    /**
     * members.access given to admitted members while no permission package has set a resolver:
     * rbac and roles set theirs (rbac in its boot, also after this one), which then decides.
     */
    private static function access(Container $c): void
    {
        if (!$c->bound(Access::class)) {
            return;
        }
        $access = $c->get(Access::class);
        if ($access->resolver() instanceof DefaultResolver) {
            $access->setResolver(new MemberAccess($access->resolver()));
        }
    }

    /**
     * The owner's notice of a refused own deletion, at MembersSettings::noticeEmail(): whether it
     * went out (no address: false; a failed mail is logged without the address: false).
     */
    private static function refusalNotice(Container $c, DynamicRecord $m, DeletePlan $plan, string $reason): bool
    {
        $to = $c->get(MembersSettings::class)->noticeEmail();
        if ($to === null) {
            return false;
        }
        try {
            $c->get(MemberMailer::class)->deletionRefused($m, $to, $plan, $reason);

            return true;
        } catch (\Throwable $e) {
            error_log(sprintf('members: deletion refused notice failed for member %s (%s)', (string) $m->getId(), $e::class));

            return false;
        }
    }

    /** The member pages' routes (Http\MembersRoutes) under the members path. */
    private static function routes(Container $c): void
    {
        if (!$c->has(RouterInterface::class)) {
            return;
        }
        $router = $c->get(RouterInterface::class);
        if ($router instanceof Router) {
            $router->getCollection()->addMany(MembersRoutes::entries($c->get(MembersConfig::class)->path()));
        }
    }

    private static function links(Container $c): Links
    {
        $app = $c->get(Application::class);
        $url = $c->get(Config::class)->get('app.url');
        $i18n = self::config($app->basePath('config/i18n.php'));
        $folio = self::config($app->basePath('config/folio.php'));

        return new Links(
            is_string($url) && $url !== '' ? $url : 'http://localhost',
            $c->get(MembersConfig::class)->path(),
            $c->get(Locales::class),
            ($i18n['url_strategy'] ?? 'prefix') === 'prefix',
            '/' . trim((string) ($folio['path'] ?? '/folio'), '/'),
        );
    }

    /**
     * The site's resources/members/ as the first path of @members, then the package's own: the
     * namespace searches its paths in registration order (Folio adds its members admin's
     * templates when it boots, after these). The package's templates are also
     * @members-package/, to extend a page from its override.
     */
    private static function siteTemplates(Container $c): void
    {
        if (!$c->has(TemplateEngineInterface::class)) {
            return;
        }
        $engine = $c->get(TemplateEngineInterface::class);
        $dir = $c->get(Application::class)->basePath('resources/members');
        if (is_dir($dir)) {
            $engine->addNamespace('members', $dir);
        }
        $engine->addNamespace('members', __DIR__ . '/templates');
        // An override that extends the page it replaces cannot name it @members/ (that is itself).
        $engine->addNamespace('members-package', __DIR__ . '/templates');
    }

    /**
     * The app's translator; without config/i18n.php and lang/ the app has none, so the
     * package brings one (site lang/ over the package copy, the default locale).
     */
    private static function translator(Container $c): Translator
    {
        try {
            return $c->get(Translator::class);
        } catch (ContainerException) { // a core before 0.37 boots none without lang/ or config/i18n.php
            $locale = $c->get(Locales::class)->default;
            $translator = new Translator($c->get(Application::class)->basePath('lang'), $locale, $locale);
            $c->instance(Translator::class, $translator);
            // The app registers t() only with the translator it boots itself; this one needs it too.
            if ($c->has(TemplateEngineInterface::class)) {
                $provider = new TranslationExtensionProvider($translator, $c->get(Locales::class)->available, $locale);
                $engine = $c->get(TemplateEngineInterface::class);
                foreach ($provider->getTemplateFunctions() as $function) {
                    $engine->addFunction($function);
                }
            }

            return $translator;
        }
    }

    /**
     * The sender: mail.from_name with the address of the mail channel (config/notify.php
     * channels.email.from); null without a from name or address, so the channel's own applies.
     */
    private static function from(Container $c): ?string
    {
        $name = $c->get(MembersConfig::class)->mailFromName();
        $from = self::config($c->get(Application::class)->basePath('config/notify.php'))['channels']['email']['from'] ?? null;
        if ($name === null || !is_string($from) || trim($from) === '') {
            return null;
        }
        $address = preg_match('/<([^<>]+)>\s*$/', $from, $m) === 1 ? $m[1] : $from;
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}<>"]+/u', ' ', $name));

        return $name . ' <' . trim($address) . '>';
    }

    /** @return array<string, mixed> */
    private static function config(string $file): array
    {
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }
}
