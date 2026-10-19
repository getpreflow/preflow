<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;

/**
 * Sends a fixed test message through a notify channel, to verify transport
 * credentials (e.g. SMTP on a live server). preflow/notify is optional for
 * devtools, so its classes are only referenced once we know it is installed.
 */
final class NotifyTestCommand implements CommandInterface
{
    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application
     * @param resource|null                  $stderr     error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'notify:test'; }
    public function getDescription(): string { return 'Send a test message through a notify channel'; }

    public function execute(array $args): int
    {
        $options = $this->parseOptions($args);
        $to = $options['to'] ?? '';
        $channel = $options['channel'] ?? 'email';

        if ($to === '' || !in_array($channel, ['email', 'sms'], true)) {
            $this->error("Usage: preflow notify:test --to=<address> [--channel=email|sms]\n");
            return 1;
        }

        $app = $this->bootApplication();
        if (!class_exists(\Preflow\Notify\Notifier::class) || !file_exists($app->basePath('config/notify.php'))) {
            $this->error("preflow/notify is not installed or config/notify.php is missing.\n");
            return 1;
        }

        try {
            $message = $channel === 'sms'
                ? new \Preflow\Notify\Sms($to, 'Preflow notify test')
                : new \Preflow\Notify\Email(
                    $to,
                    'Preflow notify test',
                    'This is a test message sent by "php preflow notify:test" at ' . date(DATE_ATOM) . '.',
                );

            $sent = $app->container()->get(\Preflow\Notify\Notifier::class)->send($message);
        } catch (\Preflow\Notify\TransportException | \InvalidArgumentException $e) {
            $this->error('Error: ' . $e->getMessage() . "\n");
            return 1;
        }

        echo 'Sent via ' . $sent->transport . ($sent->messageId !== null ? " (id {$sent->messageId})" : '') . "\n";

        // A failover delivered it, but a preferred transport failed: that is what
        // this command exists to reveal, so report it and fail.
        foreach ($sent->failures as $transport => $reason) {
            $this->error("Warning: transport \"{$transport}\" failed before falling back: {$reason}\n");
        }

        return $sent->failures === [] ? 0 : 1;
    }

    private function error(string $text): void
    {
        fwrite($this->stderr ?? STDERR, $text);
    }

    private function bootApplication(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }

    /**
     * @param list<string> $args
     * @return array<string, string>
     */
    private function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            }
        }

        return $options;
    }
}
