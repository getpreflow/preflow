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
 * key by key) through the app's translator; an app without one (no lang/ and no
 * config/i18n.php) gets a translator of its own here, in the default content locale.
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
        $translator->addDefaults('rbac', dirname(__DIR__) . '/lang');

        return new self($translator);
    }

    /** @param array<string, string|int> $params */
    public function get(string $key, array $params = []): string
    {
        return $this->translator->get('rbac.' . $key, $params);
    }
}
