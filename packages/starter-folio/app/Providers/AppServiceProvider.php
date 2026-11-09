<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Markdown;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // Bind your own services here.

        // Folio makes the job tables (the folio.tables step of `preflow migrate`), but it gives a
        // relation column no index, and the job lists filter by company. A migration file can't
        // add one: on a fresh install it runs before Folio's step has made the tables. This step
        // runs after it, on every migrate; a rerun finds the indexes and does nothing.
        $container->get(MigrationRegistry::class)->afterMigrate('app.indexes', static function () use ($container): array {
            $driver = $container->get(DataManager::class)->driver('default');
            if (!$driver instanceof PdoDriver) {
                return [];
            }
            $pdo = $driver->pdo();
            $added = [];
            // The working copies (employer area) and the published copies (public pages, API).
            foreach (['jobs', 'jobs__published'] as $table) {
                $name = 'idx_' . $table . '_company';
                // sqlite_master: Folio's tables are SQLite only for now, and so is this.
                $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = " . $pdo->quote($name))->fetchColumn();
                if ($exists === false) {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS \"{$name}\" ON \"{$table}\" (\"company\")");
                    $added[] = "added index {$name}";
                }
            }

            return $added;
        });
    }

    public function boot(Container $container): void
    {
        // markdown() renders a job description. It is safe to print because Markdown::render
        // escapes any HTML in the source.
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'markdown',
            callable: fn (?string $text): string => Markdown::render((string) $text),
            isSafe: true,
        ));
    }
}
