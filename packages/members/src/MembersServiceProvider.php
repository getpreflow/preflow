<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Core\Application;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\Exceptions\ContainerException;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Content\ContentLocale;
use Preflow\I18n\Translator;
use Preflow\I18n\TranslationExtensionProvider;
use Preflow\View\AssetCollector;
use Preflow\Members\Admission\Admission;
use Preflow\Notify\Notifier;
use Preflow\View\TemplateEngineInterface;

/**
 * Binds the members services: MembersConfig (config/members.php; a missing file means the
 * defaults), Members, Links, LinkValidity, MemberMailer and the configured Admission, and adds the
 * package copy (lang/{locale}/members.php) under the site's own. A site's resources/members/ is
 * put in front of the package templates (@members) when it exists. When the Folio
 * extension "members" is enabled, boot checks that rbac (the member roles) is enabled too
 * and that the admission class is one.
 */
final class MembersServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        self::siteTemplates($container);
        self::translator($container)->addDefaults('members', dirname(__DIR__) . '/lang');
        $container->singleton(MembersConfig::class, static fn (Container $c): MembersConfig => MembersConfig::fromFile(
            $c->get(Application::class)->basePath('config/members.php'),
        ));
        $container->singleton(Members::class, static fn (Container $c): Members => new Members(
            $c->get(DataManager::class),
            $c->get(PasswordHasherInterface::class),
        ));
        $container->singleton(LinkValidity::class, static fn (Container $c): LinkValidity => new LinkValidity(
            $c->get(Translator::class),
            $c->get(MembersConfig::class),
        ));
        $container->singleton(Links::class, static fn (Container $c): Links => self::links($c));
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
        ));
        $container->singleton(MembersView::class, static fn (Container $c): MembersView => new MembersView(
            $c->get(TemplateEngineInterface::class),
            $c->get(AssetCollector::class),
            $c->get(ContentLocale::class),
            $c->get(Viewer::class),
            $c->get(MembersConfig::class),
            $c->get(Links::class),
            $c->get(LinkValidity::class),
            $c->get(Locales::class),
            $c->get(Translator::class),
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
        $extensions = $container->get(ExtensionRegistry::class);
        if (!$extensions->isEnabled('members')) {
            return;
        }
        if (!$extensions->isEnabled('rbac')) {
            throw new \RuntimeException('The members extension needs the rbac extension for the member roles: run preflow folio:extensions:enable rbac.');
        }
        $container->get(Admission::class);
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
     * The site's resources/members/ as the first path of @members: the namespace searches its
     * paths in registration order, and Folio adds the package's own when it boots, after this.
     * The package's templates are also @members-package/, to extend a page from its override.
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
        } catch (ContainerException) {
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
