<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

/**
 * Runs a console command of the app in a fresh PHP process, as `php preflow <command>` in the
 * app's folder would: the app boots with the config files as they are on disk now (the providers
 * folio:install just listed, say), never with what the running process loaded. The child loads
 * the app's vendor/autoload.php and Preflow's Console, plus folio:install's internal probe.
 *
 * Arguments go as an argv array (no shell); input, such as a password, only on stdin.
 *
 * @since 0.85.0
 */
final class AppConsole
{
    /** Runs in the child process: `php -r CHILD -- <root> <command> [args…]`. */
    private const string CHILD = <<<'PHP'
        $root = $argv[1];
        require $root . '/vendor/autoload.php';
        $console = new \Preflow\DevTools\Console($root);
        $console->register(new \Preflow\Folio\Install\ProbeCommand());
        exit($console->run(array_merge(['preflow'], array_slice($argv, 2))));
        PHP;

    private readonly string $php;

    /** @param string|null $php PHP binary (default: the running one) */
    public function __construct(
        private readonly string $root,
        ?string $php = null,
    ) {
        $this->php = $php ?? PHP_BINARY;
    }

    /**
     * Runs the command and returns its exit code and everything it printed (stdout and stderr).
     *
     * @param list<string> $args
     * @return array{int, string}
     */
    public function run(string $command, array $args = [], string $stdin = ''): array
    {
        // stdout and stderr go to one temp file, in the order they were written; a file never blocks the child.
        $output = tmpfile();
        if ($output === false) {
            return [1, "Could not make a temp file for the output of {$command}.\n"];
        }
        $process = proc_open(
            [$this->php, '-d', 'display_errors=stderr', '-r', self::CHILD, '--', $this->root, $command, ...$args],
            [0 => ['pipe', 'r'], 1 => $output, 2 => $output],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            fclose($output);

            return [1, "Could not start PHP ({$this->php}).\n"];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $code = proc_close($process);
        rewind($output);
        $text = (string) stream_get_contents($output);
        fclose($output);

        return [$code, $text];
    }

    /**
     * Runs the probe (ProbeCommand) and returns what it answered; ['error' => …] when the child
     * failed before answering.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    public function probe(string $action, array $args = [], string $stdin = ''): array
    {
        [$code, $output] = $this->run(ProbeCommand::NAME, [$action, ...$args], $stdin);
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, ProbeCommand::MARK)) {
                $answer = json_decode(substr($line, strlen(ProbeCommand::MARK)), true);
                if (is_array($answer)) {
                    return $answer;
                }
            }
        }
        $output = trim($output);

        return ['error' => $output !== '' ? $output : "the probe exited with {$code} and printed nothing"];
    }
}
