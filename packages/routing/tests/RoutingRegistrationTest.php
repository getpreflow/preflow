<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Routing\PageExists as CorePageExists;
use Preflow\Core\Routing\PageLookups;
use Preflow\Routing\Redirect\PageExists;
use Preflow\Routing\RoutingRegistration;

/** Routing's Redirect\PageExists is one of core's page lookup sources, after core's own name. */
final class RoutingRegistrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_routing_registration_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'key' => 'k'];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_routings_name_is_a_page_lookup_source(): void
    {
        $app = Application::create($this->dir);
        $app->boot();

        $this->assertSame([CorePageExists::class, PageExists::class], $app->container()->get(PageLookups::class)->ids());
    }

    public function test_registering_twice_adds_the_name_once(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        RoutingRegistration::register($app);

        $this->assertSame([CorePageExists::class, PageExists::class], $app->container()->get(PageLookups::class)->ids());
    }
}
