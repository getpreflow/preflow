<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\SeoConfig;

final class SeoConfigTest extends TestCase
{
    public function test_missing_file_is_off_with_defaults(): void
    {
        $config = SeoConfig::fromFile(sys_get_temp_dir() . '/no-such-seo-' . bin2hex(random_bytes(4)) . '.php');

        $this->assertFalse($config->enabled());
        $this->assertNull($config->name);
        $this->assertSame(['name' => 'name', 'description' => 'meta_description'], $config->siteFields);
        $this->assertNull($config->owner);
        $this->assertSame([], $config->types);
        $this->assertNull($config->listedTypes);
        $this->assertTrue($config->robotsEnabled);
        $this->assertSame(['/--component/', '/*/--component/'], $config->disallow);
        $this->assertTrue($config->aiTraining);
        $this->assertSame([], $config->robotsExtra);
        $this->assertTrue($config->sitemap);
        $this->assertNull($config->securityContact);
        $this->assertNull($config->securityPolicy);
        $this->assertSame([], $config->themeColor);
        $this->assertTrue($config->etag);
    }

    public function test_reads_a_file(): void
    {
        $path = sys_get_temp_dir() . '/seo-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, "<?php\nreturn " . var_export(['enabled' => true, 'name' => 'Site'], true) . ";\n");

        try {
            $config = SeoConfig::fromFile($path);
        } finally {
            unlink($path);
        }

        $this->assertTrue($config->enabled());
        $this->assertSame('Site', $config->name);
    }

    public function test_from_array_coerces_types(): void
    {
        $config = SeoConfig::fromArray([
            'enabled' => 1,
            'name' => '  ',
            'site_fields' => ['name' => 'title', 'description' => 7],
            'owner' => ['type' => 'Person', 'name' => 'Ada', 'url' => 'https://ada.example', 'same_as' => 'https://github.com/ada'],
            'types' => [
                'post' => ['title' => 'headline', 'description' => ['lead', 'body'], 'image' => 'cover', 'og_type' => 'article'],
                'page' => ['og_type' => 'video'],
                'broken' => 'nope',
            ],
            'listed_types' => 'post',
            'robots' => ['enabled' => 0, 'disallow' => '/private/', 'ai_training' => '', 'extra' => 'Crawl-delay: 5'],
            'sitemap' => 0,
            'security_txt' => ['contact' => 'mailto:sec@example.com', 'policy' => ''],
            'theme_color' => ['light' => '#ffffff', 'dark' => '#000000', 'sepia' => '#704214', 'bad' => 3],
            'etag' => false,
        ]);

        $this->assertTrue($config->enabled);
        $this->assertNull($config->name);
        $this->assertSame(['name' => 'title', 'description' => '7'], $config->siteFields);
        $this->assertSame([
            'type' => 'Person',
            'name' => 'Ada',
            'url' => 'https://ada.example',
            'logo' => null,
            'same_as' => ['https://github.com/ada'],
        ], $config->owner);
        $this->assertSame([
            'post' => ['title' => ['headline'], 'description' => ['lead', 'body'], 'image' => ['cover'], 'og_type' => 'article'],
            'page' => ['title' => [], 'description' => [], 'image' => [], 'og_type' => 'website'],
        ], $config->types);
        $this->assertSame(['post'], $config->listedTypes);
        $this->assertFalse($config->robotsEnabled);
        $this->assertSame(['/private/'], $config->disallow);
        $this->assertFalse($config->aiTraining);
        $this->assertSame(['Crawl-delay: 5'], $config->robotsExtra);
        $this->assertFalse($config->sitemap);
        $this->assertSame('mailto:sec@example.com', $config->securityContact);
        $this->assertNull($config->securityPolicy);
        $this->assertSame(['light' => '#ffffff', 'dark' => '#000000'], $config->themeColor);
        $this->assertFalse($config->etag);
    }

    public function test_owner_without_a_name_is_dropped_and_type_defaults_to_organization(): void
    {
        $this->assertNull(SeoConfig::fromArray(['owner' => ['type' => 'Person']])->owner);
        $this->assertSame('Organization', SeoConfig::fromArray(['owner' => ['name' => 'Acme', 'type' => 'Thing']])->owner['type'] ?? null);
    }

    public function test_theme_color_string_is_keyed_by_empty_string(): void
    {
        $this->assertSame(['' => '#3366ff'], SeoConfig::fromArray(['theme_color' => '#3366ff'])->themeColor);
        $this->assertSame([], SeoConfig::fromArray(['theme_color' => ''])->themeColor);
    }
}
