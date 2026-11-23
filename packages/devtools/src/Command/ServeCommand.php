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

        $problem = $this->portProblem($host, (int) $port);
        if ($problem !== null) {
            fwrite(STDERR, $problem . "\n");
            return 1;
        }

        echo "Preflow dev server started on http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop.\n\n";

        $router = __DIR__ . '/../router.php';
        passthru($this->buildCommand($host, (int) $port, $docRoot, $router), $exitCode);
        return $exitCode;
    }

    /**
     * One line naming the port and --port when nothing can listen there (taken, or not allowed),
     * else null. Checked before "started" is printed, so the message never follows a claim.
     *
     * @since 0.84.2
     */
    public function portProblem(string $host, int $port): ?string
    {
        $socket = @stream_socket_server("tcp://{$host}:{$port}", $code, $message);
        if ($socket === false) {
            return "Error: {$host}:{$port} is not available ({$message}). Stop what uses it or pick another port with --port=<number>.";
        }
        fclose($socket);

        return null;
    }

    /** Every dynamic part is quoted: a project path with a space must not split into arguments. */
    public function buildCommand(string $host, int $port, string $docRoot, string $router): string
    {
        return 'php -S ' . escapeshellarg("{$host}:{$port}") . ' -t ' . escapeshellarg($docRoot) . ' ' . escapeshellarg($router);
    }
}
