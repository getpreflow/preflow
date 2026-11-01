<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\RobotsTxt;
use Preflow\View\Seo\SeoConfig;

final class RobotsTxtTest extends TestCase
{
    private const SEARCH = <<<'T'
User-agent: *
Allow: /
Disallow: /--component/
Disallow: /admin

# AI search and assistant crawlers
User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: Claude-SearchBot
User-agent: Claude-User
User-agent: PerplexityBot
User-agent: Perplexity-User
Allow: /
Disallow: /--component/
Disallow: /admin

# AI training crawlers
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: Google-Extended
User-agent: Applebot-Extended
User-agent: CCBot
User-agent: meta-externalagent

T;

    public function test_training_allowed(): void
    {
        $text = RobotsTxt::render(new SeoConfig(disallow: ['/--component/']), ['/admin'], 'https://example.com/', true);

        $this->assertSame(self::SEARCH . "Allow: /\nDisallow: /--component/\nDisallow: /admin\n\n"
            . "Sitemap: https://example.com/sitemap.xml\n# LLM-readable site index: https://example.com/llms.txt\n", $text);
    }

    public function test_training_blocked_and_extra_lines(): void
    {
        $config = new SeoConfig(disallow: ['/--component/'], aiTraining: false, robotsExtra: ['Crawl-delay: 5']);
        $text = RobotsTxt::render($config, ['/admin'], 'https://example.com', false);

        $this->assertSame(self::SEARCH . "Disallow: /\n\nCrawl-delay: 5\nSitemap: https://example.com/sitemap.xml\n", $text);
    }

    public function test_no_sitemap_line_when_off(): void
    {
        $text = RobotsTxt::render(new SeoConfig(sitemap: false), [], 'https://example.com', false);

        $this->assertStringNotContainsString('Sitemap:', $text);
        $this->assertStringNotContainsString('llms.txt', $text);
        $this->assertStringEndsWith("Allow: /\nDisallow: /--component/\nDisallow: /*/--component/\n", $text);
    }

    public function test_allowed_paths_follow_allow_root_in_each_group_that_allows(): void
    {
        $text = RobotsTxt::render(new SeoConfig(disallow: ['/--component/']), ['/folio/'], 'https://example.com', false, ['/folio/_uploads/']);

        $allowed = "Allow: /\nAllow: /folio/_uploads/\nDisallow: /--component/\nDisallow: /folio/\n";
        $this->assertStringContainsString("User-agent: *\n" . $allowed, $text);
        $this->assertStringContainsString("User-agent: Perplexity-User\n" . $allowed, $text);
        $this->assertStringContainsString("User-agent: meta-externalagent\n" . $allowed, $text);
        $this->assertSame(3, substr_count($text, 'Allow: /folio/_uploads/'));

        // Training crawlers kept out entirely get no exceptions.
        $blocked = RobotsTxt::render(new SeoConfig(aiTraining: false), ['/folio/'], 'https://example.com', false, ['/folio/_uploads/']);
        $this->assertStringContainsString("User-agent: meta-externalagent\nDisallow: /\n", $blocked);
        $this->assertSame(2, substr_count($blocked, 'Allow: /folio/_uploads/'));
    }
}
