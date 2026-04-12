<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class ServeCommand implements CommandInterface
{
    public function getName(): string { return 'serve'; }
    public function getDescription(): string { return 'Start the development server (--host=x --port=x)'; }

    public function execute(array $args): int
    {
        $host = 'localhost';
        $port = '8080';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $port = substr($arg, 7);
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            } elseif (is_numeric($arg)) {
                $port = $arg;
            }
        }

        $docRoot = getcwd() . '/public';
        if (!is_dir($docRoot)) {
            fwrite(STDERR, "Error: public/ directory not found. Run from your project root.\n");
            return 1;
        }

        echo "Preflow dev server started on http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop.\n\n";

        $router = __DIR__ . '/../router.php';
        passthru("php -S {$host}:{$port} -t {$docRoot} {$router}", $exitCode);
        return $exitCode;
    }
}
