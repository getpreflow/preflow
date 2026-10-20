<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;

/**
 * Shared plumbing for the content transfer commands: an app factory for tests,
 * explicit output streams (an export on stdout must carry nothing but the
 * archive), and the --only option.
 */
abstract class TransferCommand
{
    /** @var resource */
    protected $stdout;
    /** @var resource */
    protected $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(private readonly ?\Closure $appFactory = null, $stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    protected function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }
        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }

    /** @param string[] $args */
    protected static function only(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--only=')) {
                $only = substr($arg, 7);

                return in_array($only, ['data', 'media'], true) ? $only : null;
            }
        }

        return 'all';
    }

    /** @param string[] $args @return list<string> */
    protected static function positional(array $args): array
    {
        return array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
    }

    public function say(string $line): void
    {
        fwrite($this->stdout, $line . "\n");
    }

    public function warn(string $line): void
    {
        fwrite($this->stderr, $line . "\n");
    }
}
