<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\DevTools\Command\RoutesListCommand;

/** routes:list prints every route the booted app answers: file pages, controllers, the component endpoint. */
final class RoutesListCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/routes_list_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages/blog', 0777, true);
        mkdir($this->dir . '/app/Controllers/Api', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'routes-list-test-key-0123456789ab'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<p>home</p>');
        file_put_contents($this->dir . '/app/pages/blog/[slug].twig', '<p>{{ slug }}</p>');
        $class = 'RoutesListProbe' . bin2hex(random_bytes(4));
        $file = $this->dir . '/app/Controllers/Api/' . $class . '.php';
        file_put_contents($file, <<<PHP
            <?php
            namespace App\\Controllers\\Api;

            use Preflow\\Routing\\Attributes\\Get;
            use Preflow\\Routing\\Attributes\\Post;
            use Preflow\\Routing\\Attributes\\Route;

            #[Route('/api/places')]
            final class {$class}
            {
                #[Get('/')]
                public function index(): array { return []; }

                #[Post('/{id}')]
                public function store(): array { return []; }
            }
            PHP);
        require $file;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function listRoutes(): string
    {
        $dir = $this->dir;
        $command = new RoutesListCommand(static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->boot();

            return $app;
        });
        ob_start();
        $code = $command->execute([]);
        $out = (string) ob_get_clean();
        $this->assertSame(0, $code);

        return $out;
    }

    public function test_lists_file_pages_controllers_and_the_component_endpoint(): void
    {
        $out = $this->listRoutes();

        $this->assertMatchesRegularExpression('{^GET\s+/\s+component\s+index\.twig}m', $out);
        $this->assertMatchesRegularExpression('{^GET\s+/blog/\{slug\}\s+component\s+blog/\[slug\]\.twig}m', $out);
        $this->assertMatchesRegularExpression('{^GET\s+/api/places\s+action\s+App\\\\Controllers\\\\Api\\\\RoutesListProbe\w+@index}m', $out);
        $this->assertMatchesRegularExpression('{^POST\s+/api/places/\{id\}\s+action\s+App\\\\Controllers\\\\Api\\\\RoutesListProbe\w+@store}m', $out);
        $this->assertMatchesRegularExpression('{^ANY\s+/--component/\*\s+endpoint\s+}m', $out);
    }
}
