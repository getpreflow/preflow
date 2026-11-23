<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class ServeCommand implements CommandInterface
{
    /** @var \Closure(string): int */
    private readonly \Closure $run;

    /**
     * @param (\Closure(string): int)|null $run runs the server command and returns its exit code;
     *        passthru() by default (a seam for tests, since the real server never returns)
     * @since 0.84.2
     */
    public function __construct(?\Closure $run = null)
    {
        $this->run = $run ?? static function (string $command): int {
            passthru($command, $exitCode);

            return $exitCode;
        };
    }

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

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            fwrite(STDERR, "Error: \"{$port}\" is not a port (1-65535). Pick one with --port=<number>.\n");
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
        return ($this->run)($this->buildCommand($host, (int) $port, $docRoot, $router));
    }

    /**
     * One line naming the port and --port when nothing can listen there (taken, or not allowed),
     * else null. Checked before "started" is printed, so the message never follows a claim.
     *
     * @since 0.84.2
     */
    public function portProblem(string $host, int $port): ?string
    {
        if ($port < 1 || $port > 65535) {
            return "Error: {$port} is not a port (1-65535). Pick one with --port=<number>.";
        }
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
