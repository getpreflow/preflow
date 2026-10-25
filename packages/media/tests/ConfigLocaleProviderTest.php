<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Media\ConfigLocaleProvider;
use Preflow\Media\LocaleProvider;
use Preflow\Media\MediaServiceProvider;

final class ConfigLocaleProviderTest extends TestCase
{
    public function test_translator_locale_wins_over_the_app_locale(): void
    {
        $locale = new ConfigLocaleProvider(static fn (): ?string => 'de', 'en');

        $this->assertSame('de', $locale->current());
        $this->assertSame('en', $locale->default());
    }

    public function test_app_locale_without_a_translator(): void
    {
        $locale = new ConfigLocaleProvider(static fn (): ?string => null, 'fr');

        $this->assertSame('fr', $locale->current());
        $this->assertSame('fr', $locale->default());
    }

    public function test_the_provider_binds_it_with_the_bound_translator_or_app_locale(): void
    {
        $app = Application::create(['app' => ['locale' => 'it']]);
        $app->registerProvider(new MediaServiceProvider());
        $c = $app->container();

        $this->assertSame('it', $c->get(LocaleProvider::class)->current());
        $c->instance(\Preflow\I18n\Translator::class, new \Preflow\I18n\Translator(sys_get_temp_dir(), 'de', 'it'));
        $this->assertSame('de', $c->get(LocaleProvider::class)->current());
        $this->assertSame('it', $c->get(LocaleProvider::class)->default());
    }

    public function test_without_app_locale_it_is_english(): void
    {
        $app = Application::create(['app' => []]);
        $app->registerProvider(new MediaServiceProvider());

        $this->assertSame('en', $app->container()->get(LocaleProvider::class)->current());
    }
}
