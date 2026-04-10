<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class MakeMigrationCommand implements CommandInterface
{
    public function getName(): string { return 'make:migration'; }
    public function getDescription(): string { return 'Create a new migration'; }

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null) {
            fwrite(STDERR, "Usage: preflow make:migration <name>\n");
            return 1;
        }

        $dir = getcwd() . '/migrations';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_' . $name . '.php';

        $php = <<<'PHP'
        <?php

        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        use Preflow\Data\Migration\Table;

        return new class extends Migration
        {
            public function up(Schema $schema): void
            {
                // $schema->create('table_name', function (Table $table) {
                //     $table->uuid('uuid')->primary();
                //     $table->string('name');
                //     $table->timestamps();
                // });
            }

            public function down(Schema $schema): void
            {
                // $schema->drop('table_name');
            }
        };
        PHP;

        file_put_contents($dir . '/' . $filename, $php);
        echo "Migration created: migrations/{$filename}\n";
        return 0;
    }
}
