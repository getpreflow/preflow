<?php

declare(strict_types=1);

namespace Preflow\I18n\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;

/**
 * preflow translate:plan <collection> --locales=de,fr [--scope=needed|all] [--ids=a,b] [--note=…] [--start] [--confirm-live]:
 * plans a machine-translation job over any registered text source and prints its id and estimate.
 * With --start the job is queued for translate:work; a source whose writes are public right away
 * also needs --confirm-live, or the worker stops the job before writing anything.
 */
final class TranslatePlanCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(private readonly ?\Closure $appFactory = null) {}

    public function getName(): string { return 'translate:plan'; }
    public function getDescription(): string { return 'Plan a machine-translation job: <collection> --locales=de,fr [--scope=needed|all] [--ids=a,b] [--note=…] [--start] [--confirm-live]'; }

    public function execute(array $args): int
    {
        $collection = null;
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
                $options[$key] = $value;
            } elseif ($collection === null) {
                $collection = $arg;
            }
        }
        $usage = "Usage: preflow translate:plan <collection> --locales=de,fr [--scope=needed|all] [--ids=a,b] [--note=…] [--start] [--confirm-live]\n";
        $locales = array_values(array_filter(array_map('trim', explode(',', (string) ($options['locales'] ?? '')))));
        $scope = Scope::tryFrom((string) ($options['scope'] ?? Scope::Needed->value));
        if ($collection === null || $locales === [] || $scope === null) {
            fwrite(STDERR, $usage);

            return 1;
        }

        $container = $this->app()->container();
        if (!$container->bound(TextSources::class) || !$container->bound(JobRunner::class)) {
            fwrite(STDERR, "Machine translation is not set up: add Preflow\\I18n\\Machine\\MachineServiceProvider to config/providers.php.\n");

            return 1;
        }
        $sources = $container->get(TextSources::class);
        if (!$sources->has($collection)) {
            fwrite(STDERR, "No text source for \"{$collection}\". Available: " . (implode(', ', $sources->collections()) ?: 'none') . ".\n");

            return 1;
        }
        if (class_exists(\Preflow\Data\Locales::class) && $container->bound(\Preflow\Data\Locales::class)) {
            $offered = $container->get(\Preflow\Data\Locales::class);
            foreach ($locales as $locale) {
                if (!$offered->has($locale)) {
                    fwrite(STDERR, "The app does not offer the language \"{$locale}\" (it offers: " . implode(', ', $offered->available) . ").\n");

                    return 1;
                }
            }
        }

        $runner = $container->get(JobRunner::class);
        $ids = isset($options['ids']) && $options['ids'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $options['ids'])))) : null;
        $note = isset($options['note']) && $options['note'] !== '' ? $options['note'] : null;
        $job = $runner->plan($collection, $locales, $scope, $ids, $note);
        if (array_key_exists('start', $options)) {
            $job = $runner->start($job->id, array_key_exists('confirm-live', $options));
        }

        echo 'Job ' . $job->id . ' (' . $job->state . '): ' . count($job->items) . ' items, ' . implode(',', $job->locales) . ", {$job->scope->value}\n";

        return 0;
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
