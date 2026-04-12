<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class KeyGenerateCommand implements CommandInterface
{
    public function getName(): string { return 'key:generate'; }
    public function getDescription(): string { return 'Generate a random APP_KEY in .env'; }

    public function execute(array $args): int
    {
        return $this->executeInDir(getcwd());
    }

    public function executeInDir(string $dir): int
    {
        $envPath = $dir . '/.env';

        if (!file_exists($envPath)) {
            fwrite(STDERR, "Error: .env file not found. Copy .env.example first.\n");
            return 1;
        }

        $key = bin2hex(random_bytes(16));
        $contents = file_get_contents($envPath);
        $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents);
        file_put_contents($envPath, $contents);

        echo "Application key set: {$key}\n";
        return 0;
    }
}
