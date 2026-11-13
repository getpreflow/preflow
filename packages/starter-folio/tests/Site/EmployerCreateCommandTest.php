<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Console\EmployerCreateCommand;
use App\Models\Company;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Publishing\Publisher;
use Tests\Support\StarterApp;

final class EmployerCreateCommandTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    /** @return array{int, string} exit code and output */
    private function runCommand(array $args): array
    {
        $previous = getcwd();
        chdir($this->starterRoot);
        ob_start();
        try {
            $code = (new EmployerCreateCommand())->execute($args);
        } finally {
            $output = (string) ob_get_clean();
            chdir($previous ?: '/');
        }

        return [$code, $output];
    }

    public function test_creates_company_and_user_and_prints_password_once(): void
    {
        [$code, $output] = $this->runCommand(['new@acme.test', '--company=Acme Rockets']);

        $this->assertSame(0, $code);
        $this->assertSame(1, preg_match('/^Created new@acme\.test for Acme Rockets\. Password \(shown once\): ([0-9a-f]{18})$/m', $output, $match), $output);
        $this->assertSame(1, substr_count($output, $match[1]));

        $data = $this->container()->get(DataManager::class);
        $company = $data->query(Company::class)->where('name', 'Acme Rockets')->first();
        $user = $data->query(User::class)->where('email', 'new@acme.test')->first();
        $this->assertSame('acme-rockets', $company->slug);
        $this->assertNotNull($this->container()->get(Publisher::class)->snapshot('company', $company->uuid), 'the new company is public');
        $this->assertSame($company->uuid, $user->company);
        $this->assertTrue(password_verify($match[1], $user->passwordHash));
        $this->assertNotSame($match[1], $user->passwordHash);
    }

    public function test_the_email_is_lowercased_so_the_duplicate_check_sees_it(): void
    {
        [$code] = $this->runCommand(['  Mixed@Acme.test ', '--company=Acme Rockets']);
        $this->assertSame(0, $code);
        $user = $this->container()->get(DataManager::class)->query(User::class)->where('email', 'mixed@acme.test')->first();
        $this->assertNotNull($user);

        [$code, $output] = $this->runCommand(['MIXED@acme.test', '--company=Acme Rockets']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already exists', $output);
    }

    public function test_an_existing_company_is_reused(): void
    {
        [$code] = $this->runCommand(['second@northwind.test', '--company=Northwind Labs']);

        $this->assertSame(0, $code);
        $data = $this->container()->get(DataManager::class);
        $this->assertSame(7, $data->query(Company::class)->get()->total());
    }

    public function test_duplicate_email_exits_1(): void
    {
        [$code, $output] = $this->runCommand(['employer@example.com', '--company=Northwind Labs']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('A user with this email already exists.', $output);
        $this->assertStringNotContainsString('Password', $output);
    }

    public function test_missing_company_prints_usage(): void
    {
        [$code, $output] = $this->runCommand(['x@y.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('employer:create <email> --company="<name>"', $output);
    }
}
