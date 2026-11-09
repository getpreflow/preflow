<?php

declare(strict_types=1);

namespace App\Console;

use App\Models\Staff;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;

/**
 * Creates a staff account for Folio on the server: the first admin of a new install, and anyone
 * after that. The password is generated and printed once; only its hash is stored.
 */
final class StaffCreateCommand implements CommandInterface
{
    private const string USAGE = 'Usage: staff:create <email> --role=admin|editor';

    /** The roles config/roles.php defines for staff. */
    private const array ROLES = ['admin', 'editor'];

    public function getName(): string { return 'staff:create'; }
    public function getDescription(): string { return 'Create a Folio staff account: <email> --role=admin|editor'; }

    public function execute(array $args): int
    {
        $email = null;
        $role = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--role=')) {
                $role = trim(substr($arg, strlen('--role=')));
            } elseif (!str_starts_with($arg, '--')) {
                $email = strtolower(trim($arg));
            }
        }
        if ($email === null || $email === '' || $role === null) {
            echo self::USAGE . "\n";

            return 1;
        }
        if (!in_array($role, self::ROLES, true)) {
            echo "Role must be admin or editor.\n";

            return 1;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            echo "\"{$email}\" is not an email address.\n";

            return 1;
        }

        try {
            $app = Application::create(getcwd() ?: '.');
            $app->boot();
            $data = $app->container()->get(DataManager::class);

            if ($data->query(Staff::class)->where('email', $email)->first() !== null) {
                echo "A staff account with this email already exists.\n";

                return 1;
            }

            $password = bin2hex(random_bytes(9));
            $staff = new Staff();
            $staff->uuid = bin2hex(random_bytes(16));
            $staff->email = $email;
            $staff->passwordHash = (new NativePasswordHasher())->hash($password);
            $staff->roles = [$role];
            $staff->createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $data->save($staff);
        } catch (\Throwable $e) {
            echo 'Error: ' . $e->getMessage() . "\n";

            return 1;
        }

        // The only place the password ever appears: it is not logged, and cannot be shown again.
        echo "Created {$email} ({$role}). Password (shown once): {$password}\n";

        return 0;
    }
}
