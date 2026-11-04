<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\NotFoundFilter;

final class NotFoundFilterTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function paths(): array
    {
        return [
            'wp login' => ['/wp-login.php', true],
            'dotenv' => ['/.env', true],
            'git config' => ['/x/.git/config', true],
            'xmlrpc' => ['/xmlrpc.php', true],
            'sql dump' => ['/backup.sql', true],
            'uppercase prefix' => ['/WP-ADMIN/', true],
            'old page' => ['/old-page', false],
            'localized page' => ['/de/werkstatt', false],
            'html page' => ['/page.html', false],
            'missing image' => ['/images/missing.jpg', false],
        ];
    }

    #[DataProvider('paths')]
    public function test_built_in_rules(string $path, bool $ignored): void
    {
        $this->assertSame($ignored, (new NotFoundFilter())->ignores($path));
    }

    public function test_configured_prefix_and_extension_are_ignored(): void
    {
        $filter = new NotFoundFilter(['/legacy/', '.json']);

        $this->assertTrue($filter->ignores('/legacy/page'));
        $this->assertTrue($filter->ignores('/data.json'));
        $this->assertFalse($filter->ignores('/data.xml'));
    }
}
