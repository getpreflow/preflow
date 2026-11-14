<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Locales;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\WriteOutcomeStatus;
use Preflow\Media\MediaLibrary;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Translation\AltTextSource;

final class AltTextSourceTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function source(?MediaLibrary $lib = null): AltTextSource
    {
        return new AltTextSource($lib ?? $this->library(), new Locales(['en', 'de'], 'en'));
    }

    private function asset(MediaLibrary $lib, array $alt, string $title = 'The door'): string
    {
        $asset = $lib->upload($this->upload($this->png(10, 10), 'door.png', 'image/png'), null);

        return $lib->update($asset->id, ['alt' => $alt, 'title' => $title])->id;
    }

    public function test_the_unit_is_alt_locale_from_the_default_text(): void
    {
        $lib = $this->library();
        $id = $this->asset($lib, ['en' => 'A red door', 'de' => '']);

        $units = $this->source($lib)->units($id, 'de', Scope::Needed);

        $this->assertCount(1, $units);
        $this->assertSame('alt.de', $units[0]->id);
        $this->assertSame('A red door', $units[0]->source);
        $this->assertSame('en', $units[0]->from);
        $this->assertSame('de', $units[0]->to);
        $this->assertSame('alt', $units[0]->kind);
        $this->assertNull($units[0]->current);
        $this->assertSame(['asset' => 'The door'], $units[0]->context);
    }

    public function test_needed_skips_a_translated_text_and_all_offers_it_again(): void
    {
        $lib = $this->library();
        $id = $this->asset($lib, ['en' => 'A red door', 'de' => 'Eine rote Tür']);
        $source = $this->source($lib);

        $this->assertSame([], $source->units($id, 'de', Scope::Needed));
        $all = $source->units($id, 'de', Scope::All);
        $this->assertSame('Eine rote Tür', $all[0]->current);
    }

    public function test_no_default_text_and_the_default_language_give_no_units(): void
    {
        $lib = $this->library();
        $empty = $this->asset($lib, ['en' => '', 'de' => '']);
        $filled = $this->asset($lib, ['en' => 'A door']);
        $source = $this->source($lib);

        $this->assertSame([], $source->units($empty, 'de', Scope::All));
        $this->assertSame([], $source->units($filled, 'en', Scope::All));
        $this->assertSame([], $source->units($filled, 'fr', Scope::All));
        $this->assertSame([$filled], $source->items());
    }

    public function test_write_stores_the_text_for_that_language_only_and_is_live(): void
    {
        $lib = $this->library();
        $id = $this->asset($lib, ['en' => 'A red door']);
        $source = $this->source($lib);
        $source->units($id, 'de', Scope::Needed);

        $outcome = $source->write($id, 'de', ['alt.de' => 'Eine rote Tür']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertSame(['en' => 'A red door', 'de' => 'Eine rote Tür'], $lib->find($id)->alt);
        $this->assertTrue($source->writesLive());
    }

    public function test_a_text_saved_since_it_was_read_wins(): void
    {
        $lib = $this->library();
        $id = $this->asset($lib, ['en' => 'A red door']);
        $source = $this->source($lib);
        $source->units($id, 'de', Scope::Needed);
        $lib->update($id, ['alt' => ['de' => 'Von Hand']]);

        $outcome = $source->write($id, 'de', ['alt.de' => 'Maschine']);

        $this->assertSame(WriteOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame(['alt.de'], $outcome->leftAlone);
        $this->assertSame('Von Hand', $lib->find($id)->alt['de']);
    }

    public function test_a_gone_asset_is_skipped(): void
    {
        $this->assertSame(WriteOutcomeStatus::Skipped, $this->source()->write('nope', 'de', ['alt.de' => 'x'])->status);
    }

    public function test_the_provider_registers_it_only_for_a_multilingual_app_and_acts_on_nothing(): void
    {
        $multi = $this->app(new Locales(['en', 'de'], 'en'));
        $this->assertTrue($multi->container()->get(TextSources::class)->has(AltTextSource::COLLECTION));
        $this->assertSame(['media_alt'], $multi->container()->get(TextSources::class)->collections());

        $single = $this->app(Locales::single('en'));
        $this->assertFalse($single->container()->get(TextSources::class)->has(AltTextSource::COLLECTION));
        // Its caches (storage/) go to the test's own directory, never the checkout's.
        $this->assertSame(realpath($this->assetRoot), realpath($multi->basePath()));
    }

    private function app(Locales $locales): Application
    {
        $app = $this->appInAssetRoot(['app' => ['locale' => 'en']]);
        $app->container()->instance(Locales::class, $locales);
        $media = new MediaServiceProvider();
        $app->registerProvider($media);
        $app->registerProvider(new \Preflow\I18n\Machine\MachineServiceProvider());
        $media->boot($app->container()); // the part of a boot that adds the source, with these locales

        return $app;
    }
}
