<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Media\ContentLocaleProvider;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\Media\ImageTag;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

/** Folio's content locale is the media core's locale: alt text follows the page's language. */
#[RequiresPhpExtension('gd')]
final class ContentLocaleProviderTest extends TestCase
{
    use AssetFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_follows_the_content_locale(): void
    {
        $content = new ContentLocale(new Locales(['en', 'de'], 'en'));
        $provider = new ContentLocaleProvider($content);

        $this->assertSame('en', $provider->current());
        $content->set('de');
        $this->assertSame('de', $provider->current());
        $this->assertSame('en', $provider->default());
    }

    public function test_image_tag_alt_on_a_german_page(): void
    {
        $lib = $this->library();
        $asset = $lib->upload($this->upload($this->png(100, 50), 'a.png'), null);
        $lib->update($asset->id, ['alt' => ['en' => 'Sea', 'de' => 'Meer']]);
        $other = $lib->upload($this->upload($this->png(100, 50), 'b.png'), null);
        $lib->update($other->id, ['alt' => ['en' => 'Sky']]);
        $content = new ContentLocale(new Locales(['en', 'de'], 'en'));
        $tag = new ImageTag($lib, new ContentLocaleProvider($content), [], new AssetCollector(new NonceGenerator()), '/folio/_uploads');

        $content->set('de');

        $this->assertStringContainsString('alt="Meer"', $tag->render($asset->id));
        $this->assertStringContainsString('alt="Sky"', $tag->render($other->id));
    }
}
