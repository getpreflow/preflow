<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class ServeCommand implements CommandInterface
{
    public function getName(): string { return 'serve'; }
    public function getDescription(): string { return 'Start the development server'; }

    public function execute(array $args): int
    {
        $host = $args[0] ?? 'localhost';
        $port = $args[1] ?? '8080';

        echo "Preflow dev server started on http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop.\n\n";

        $docRoot = getcwd() . '/public';
        if (!is_dir($docRoot)) {
            fwrite(STDERR, "Error: public/ directory not found. Run from your project root.\n");
            return 1;
        }

        $router = $docRoot . '/index.php';
        passthru("php -S {$host}:{$port} -t {$docRoot} {$router}", $exitCode);
        return $exitCode;
    }
}
