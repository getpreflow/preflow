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
 * no lang/ and no config/i18n.php) gets a translator of its own here. get() answers in the
 * translator's language: on an admin request the user's interface language (Folio sets it).
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

    /** rbac's English strings alone, for a field type built without the container (tests). */
    public static function english(): self
    {
        $translator = new Translator(dirname(__DIR__) . '/lang/.none', 'en', 'en');
        $translator->addDefaults('rbac', dirname(__DIR__) . '/lang');

        return new self($translator);
    }

    /**
     * @param array<string, string|int> $params
     * @param string|null $locale another language than the translator's (default: its current one)
     */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        return $this->translator->get('rbac.' . $key, $params, $locale);
    }

    /**
     * A role's label as the admin reads it: a package that seeds a role ships its label as
     * {group}.roles.{population}.{name} (rbac's "admin", members' "pending" …); while the
     * stored label is still that key's English text, the text in the translator's language.
     * A label the owner changed is theirs and stays as stored.
     */
    public function roleLabel(string $population, string $name, string $stored): string
    {
        foreach ($this->translator->groups() as $group) {
            if ($this->translator->scope($group) !== 'admin') {
                continue;
            }
            $key = $group . '.roles.' . $population . '.' . $name;
            if ($this->translator->get($key, [], 'en') === $stored) {
                return $this->translator->get($key);
            }
        }

        return $stored;
    }
}
