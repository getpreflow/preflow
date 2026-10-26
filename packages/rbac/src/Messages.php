<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\Exceptions\ContainerException;
use Preflow\Data\Locales;
use Preflow\I18n\Translator;

/**
 * The rbac strings (lang/{locale}/rbac.php, a site's lang/{locale}/rbac.php laid over them
 * key by key) through the app's translator; an app without one (a core before 0.37 with
 * no lang/ and no config/i18n.php) gets a translator of its own here. Folio's admin is English-only and
 * the translator's locale is the content locale, so get() answers in English by default;
 * de and fr are there for a caller that asks for them.
 */
final class Messages
{
    public function __construct(private readonly Translator $translator) {}

    public static function boot(Container $c): self
    {
        try {
            $translator = $c->get(Translator::class);
        } catch (ContainerException) { // not registered, and not autowirable
            $locale = $c->get(Locales::class)->default;
            $translator = new Translator($c->get(Application::class)->basePath('lang'), $locale, $locale);
        }
        // Declared in composer.json (extra.preflow.translations); this covers apps whose Composer
        // metadata doesn't list the package (test apps, a core before 0.37).
        $translator->addDefaultsIfMissing('rbac', dirname(__DIR__) . '/lang', 'admin');

        return new self($translator);
    }

    /** @param array<string, string|int> $params */
    public function get(string $key, array $params = [], string $locale = 'en'): string
    {
        return $this->translator->get('rbac.' . $key, $params, $locale);
    }
}
