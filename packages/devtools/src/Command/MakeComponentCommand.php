<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class MakeComponentCommand implements CommandInterface
{
    public function getName(): string { return 'make:component'; }
    public function getDescription(): string { return 'Create a new component'; }

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null) {
            fwrite(STDERR, "Usage: preflow make:component <Name>\n");
            return 1;
        }

        $dir = getcwd() . '/app/Components/' . $name;
        if (is_dir($dir)) {
            fwrite(STDERR, "Component {$name} already exists.\n");
            return 1;
        }

        mkdir($dir, 0755, true);

        // PHP file
        $php = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Components\\{$name};

        use Preflow\\Components\\Component;

        final class {$name} extends Component
        {
            public function resolveState(): void
            {
                // Load state from props, database, etc.
            }

            public function actions(): array
            {
                return [];
            }
        }
        PHP;

        file_put_contents($dir . '/' . $name . '.php', $php);

        // Twig file
        $twig = <<<TWIG
        <div class="{$this->kebab($name)}">
            {# Component template #}
        </div>

        {% apply css %}
        .{$this->kebab($name)} {
            /* Component styles */
        }
        {% endapply %}

        {% apply js %}
        // Component scripts
        {% endapply %}
        TWIG;

        file_put_contents($dir . '/' . $name . '.twig', $twig);

        echo "Component created: app/Components/{$name}/\n";
        echo "  - {$name}.php\n";
        echo "  - {$name}.twig\n";
        return 0;
    }

    private function kebab(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
