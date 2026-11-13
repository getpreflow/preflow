<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Markdown;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\View\Seo\SeoContext;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // Bind your own services here.

        // preflow/publishing makes the job tables (the publishing.tables step of `preflow migrate`), but
        // the framework doesn't index relation columns yet, and the job lists filter by company. A
        // migration file can't add the index: on a fresh install it runs before that step has made the tables.
        // This step runs after it, on every migrate; a rerun finds the indexes and does nothing.
        $container->get(MigrationRegistry::class)->afterMigrate('app.indexes', static function () use ($container): array {
            $driver = $container->get(DataManager::class)->driver('default');
            if (!$driver instanceof PdoDriver) {
                return [];
            }
            $pdo = $driver->pdo();
            // The starter runs on SQLite. On MySQL the relation column is TEXT, which takes an index
            // only with a prefix length: add one there by hand if you move.
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                return [];
            }
            $added = [];
            // The working copies (employer area) and the published copies (public pages, API).
            foreach (['jobs', 'jobs__published'] as $table) {
                $name = 'idx_' . $table . '_company';
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
        $engine = $container->get(TemplateEngineInterface::class);

        // markdown() renders a job description. It is safe to print because Markdown::render
        // escapes any HTML in the source.
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'markdown',
            callable: fn (?string $text): string => Markdown::render((string) $text),
            isSafe: true,
        ));

        // page_description() is the description SEO chose for the Folio record being shown (its
        // "SEO & sharing" section, else the fields config/seo.php names). SEO prints the share
        // cards but leaves <meta name="description"> to the layout, which gets it from here.
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'page_description',
            callable: fn (): string => $container->has(SeoContext::class)
                ? ($container->get(SeoContext::class)->get()?->description ?? '')
                : '',
        ));
    }
}
