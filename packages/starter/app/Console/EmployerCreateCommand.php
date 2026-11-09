<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\Slugs;
use App\Models\Company;
use App\Models\User;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Validation\ValidationException;

/**
 * Creates an employer account (and its company, if it is new) on the server, where there is no
 * sign-up page. The password is generated and printed once; only its hash is stored.
 */
final class EmployerCreateCommand implements CommandInterface
{
    private const string USAGE = 'Usage: employer:create <email> --company="<name>"';

    public function getName(): string { return 'employer:create'; }
    public function getDescription(): string { return 'Create an employer account: <email> --company="<name>"'; }

    public function execute(array $args): int
    {
        $email = null;
        $companyName = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--company=')) {
                $companyName = trim(substr($arg, strlen('--company=')));
            } elseif (!str_starts_with($arg, '--')) {
                $email = strtolower(trim($arg));
            }
        }
        if ($email === null || $email === '' || $companyName === null || $companyName === '') {
            echo self::USAGE . "\n";

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

            if ($data->query(User::class)->where('email', $email)->first() !== null) {
                echo "A user with this email already exists.\n";

                return 1;
            }

            $company = $data->query(Company::class)->where('name', $companyName)->first();
            if ($company === null) {
                $company = new Company();
                $company->uuid = bin2hex(random_bytes(16));
                $company->name = $companyName;
                $company->slug = Slugs::unique($data, Company::class, $companyName);
                $data->save($company);
            }

            $password = bin2hex(random_bytes(9));
            $user = new User();
            $user->uuid = bin2hex(random_bytes(16));
            $user->email = $email;
            $user->passwordHash = (new NativePasswordHasher())->hash($password);
            $user->roles = ['employer'];
            $user->company = $company->uuid;
            $user->createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $data->save($user);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                echo $field . ': ' . implode(' ', $messages) . "\n";
            }

            return 1;
        } catch (\Throwable $e) {
            echo 'Error: ' . $e->getMessage() . "\n";

            return 1;
        }

        // The only place the password ever appears: it is not logged, and cannot be shown again.
        echo "Created {$email} for {$companyName}. Password (shown once): {$password}\n";

        return 0;
    }
}
