<?php

declare(strict_types=1);

namespace Tests\Site;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Tests\Support\StarterApp;

/** Company::$summary is translatable: on a site with two languages each page and answer shows one. */
final class CompanyLanguagesTest extends TestCase
{
    use StarterApp;

    private const string ENGLISH = 'Logistics software from Berlin, in English.';
    private const string GERMAN = 'Logistiksoftware aus Berlin, auf Deutsch.';

    protected function setUp(): void
    {
        $this->bootStarter();
        file_put_contents($this->starterRoot . '/config/i18n.php', '<?php return ' . var_export([
            'default' => 'en',
            'available' => ['en', 'de'],
            'fallback' => 'en',
            'url_strategy' => 'prefix',
            'cookie' => false,
        ], true) . ';');
        $data = $this->container()->get(DataManager::class);
        $company = $data->queryType('company')->where('slug', 'northwind-labs')->first();
        $company->set('summary', ['en' => self::ENGLISH, 'de' => self::GERMAN]);
        $data->saveType($company);
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function metaDescription(string $html): ?string
    {
        return preg_match('/<meta name="description" content="([^"]*)"/', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
    }

    public function test_the_company_page_shows_its_language(): void
    {
        foreach (['/companies/northwind-labs' => self::ENGLISH, '/de/companies/northwind-labs' => self::GERMAN] as $path => $summary) {
            $page = $this->get($path);
            $page->assertOk();
            $this->assertStringContainsString('<p class="company__summary">' . $summary . '</p>', $page->body(), $path);
            $this->assertSame($summary, $this->metaDescription($page->body()), $path);
        }
    }

    public function test_the_companies_api_reads_as_the_read_class_does(): void
    {
        // A locale map without ?locale=, one language with it.
        $this->assertSame(['en' => self::ENGLISH, 'de' => self::GERMAN], $this->json('GET', '/api/companies/northwind-labs')->json('data.summary'));
        $this->assertSame(self::GERMAN, $this->json('GET', '/api/companies/northwind-labs?locale=de')->json('data.summary'));
        $list = $this->json('GET', '/api/companies?locale=de')->json('data');
        $this->assertContains(self::GERMAN, array_column($list, 'summary'));
    }
}
