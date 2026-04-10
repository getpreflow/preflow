<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class MakeModelCommand implements CommandInterface
{
    public function getName(): string { return 'make:model'; }
    public function getDescription(): string { return 'Create a new model'; }

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null) {
            fwrite(STDERR, "Usage: preflow make:model <Name>\n");
            return 1;
        }

        $dir = getcwd() . '/app/Models';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $path = $dir . '/' . $name . '.php';
        if (file_exists($path)) {
            fwrite(STDERR, "Model {$name} already exists.\n");
            return 1;
        }

        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
        $php = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Models;

        use Preflow\\Data\\Model;
        use Preflow\\Data\\Attributes\\Entity;
        use Preflow\\Data\\Attributes\\Id;
        use Preflow\\Data\\Attributes\\Field;
        use Preflow\\Data\\Attributes\\Timestamps;

        #[Entity(table: '{$table}', storage: 'sqlite')]
        final class {$name} extends Model
        {
            #[Id]
            public string \$uuid = '';

            #[Field]
            public string \$name = '';

            #[Timestamps]
            public ?\\DateTimeImmutable \$createdAt = null;
            public ?\\DateTimeImmutable \$updatedAt = null;
        }
        PHP;

        file_put_contents($path, $php);
        echo "Model created: app/Models/{$name}.php\n";
        return 0;
    }
}
