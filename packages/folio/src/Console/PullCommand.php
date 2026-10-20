<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Transfer\CommandRunner;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Importer;
use Preflow\Folio\Transfer\ProcessRunner;
use Preflow\Folio\Transfer\TransferException;

/**
 * Live content to local: runs folio:export on a configured remote over SSH
 * (the user's agent and ~/.ssh/config; host keys are never ignored), then
 * imports it after a local backup. A failed or broken export changes nothing.
 */
final class PullCommand extends TransferCommand implements CommandInterface
{
    private readonly CommandRunner $runner;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(?\Closure $appFactory = null, ?CommandRunner $runner = null, $stdout = null, $stderr = null)
    {
        parent::__construct($appFactory, $stdout, $stderr);
        $this->runner = $runner ?? new ProcessRunner();
    }

    public function getName(): string
    {
        return 'folio:pull';
    }

    public function getDescription(): string
    {
        return 'Copy live content from a remote (config folio.remotes) to this copy <remote> [--dry-run] [--only=data|media]';
    }

    public function execute(array $args): int
    {
        $only = self::only($args);
        $name = self::positional($args)[0] ?? null;
        if ($only === null || $name === null) {
            $this->warn('Usage: folio:pull <remote> [--dry-run] [--only=data|media]');

            return 1;
        }

        $app = $this->app();
        $cfg = is_file($app->basePath('config/folio.php')) ? (require $app->basePath('config/folio.php')) : [];
        $remote = is_array($cfg) ? ($cfg['remotes'][$name] ?? null) : null;
        if (is_array($remote) && is_string($remote['ssh'] ?? null) && str_starts_with($remote['ssh'], '-')) {
            $this->warn("Remote \"{$name}\": 'ssh' must be a host or user@host, not an ssh option.");

            return 1;
        }
        if (!is_array($remote) || !is_string($remote['ssh'] ?? null) || $remote['ssh'] === '' || !is_string($remote['path'] ?? null)) {
            $this->warn("Unknown remote \"{$name}\": add it under 'remotes' in config/folio.php (ssh, path, optional port and php).");

            return 1;
        }

        $remoteCommand = 'cd ' . escapeshellarg($remote['path']) . ' && ' . escapeshellarg((string) ($remote['php'] ?? 'php'))
            . ' preflow folio:export --stdout' . ($only !== 'all' ? ' --only=' . $only : '');
        $argv = ['ssh', '-o', 'BatchMode=yes'];
        if (isset($remote['port'])) {
            array_push($argv, '-p', (string) (int) $remote['port']);
        }
        array_push($argv, $remote['ssh'], $remoteCommand);

        $tmp = sys_get_temp_dir() . '/folio-pull-' . bin2hex(random_bytes(6)) . '.tar';
        $umask = umask(0077); // the export holds live content: readable by this user only
        $file = fopen($tmp, 'w+b');
        umask($umask);
        try {
            $this->say("Exporting from {$name} ({$remote['ssh']})…");
            $code = $this->runner->run($argv, $file, $this->stderr);
            if ($code !== 0) {
                $this->warn("Remote export failed (exit {$code}); nothing was changed.");

                return 1;
            }

            rewind($file);
            $importer = new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
            try {
                $plan = $importer->read($file, $only);
            } catch (TransferException $e) {
                $this->warn('The remote export is not usable (' . $e->getMessage() . '); nothing was changed.');
                $this->warn('If the remote printed something besides the archive (PHP notices, shell startup output in ~/.bashrc), fix that and try again.');

                return 1;
            }

            return ImportCommand::applyPlan($this, $app, $importer, $plan, in_array('--dry-run', $args, true), 'pull');
        } finally {
            fclose($file);
            @unlink($tmp);
        }
    }
}
