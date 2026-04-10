<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class MakeControllerCommand implements CommandInterface
{
    public function getName(): string { return 'make:controller'; }
    public function getDescription(): string { return 'Create a new controller'; }

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null) {
            fwrite(STDERR, "Usage: preflow make:controller <Name>\n");
            return 1;
        }

        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        $dir = getcwd() . '/app/Controllers';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $path = $dir . '/' . $name . '.php';
        if (file_exists($path)) {
            fwrite(STDERR, "Controller {$name} already exists.\n");
            return 1;
        }

        $kebab = strtolower(preg_replace('/Controller$/', '', $name));
        $php = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Controllers;

        use Preflow\\Routing\\Attributes\\Route;
        use Preflow\\Routing\\Attributes\\Get;
        use Preflow\\Routing\\Attributes\\Post;

        #[Route('/api/{$kebab}')]
        final class {$name}
        {
            #[Get('/')]
            public function index(): \\Nyholm\\Psr7\\Response
            {
                return new \\Nyholm\\Psr7\\Response(200, ['Content-Type' => 'application/json'], '[]');
            }
        }
        PHP;

        file_put_contents($path, $php);
        echo "Controller created: app/Controllers/{$name}.php\n";
        return 0;
    }
}
