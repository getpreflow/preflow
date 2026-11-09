<?php

declare(strict_types=1);

namespace App\Seeds;

use App\Jobs\Slugs;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Core\Config\Config;
use Preflow\Data\DataManager;

/**
 * Seven fictional companies, forty jobs and one demo employer, so a fresh install has something
 * to show. Ids and dates are fixed: running it again updates the same rows.
 */
final class DemoSeeder
{
    private const string EMPLOYER_EMAIL = 'employer@example.com';
    private const string EMPLOYER_PASSWORD = 'demo-employer';

    public function __construct(private readonly Config $config) {}

    public function run(DataManager $data): void
    {
        // The demo employer's password is public (it is printed below), so production must never get it.
        if ((int) $this->config->get('app.debug', 0) === 0) {
            echo "Demo data is only seeded while APP_DEBUG is on.\n";

            return;
        }

        $demo = require __DIR__ . '/demo-data.php';

        $companyIds = [];
        foreach ($demo['companies'] as $n => $row) {
            $company = new Company();
            $company->uuid = $companyIds[$n] = md5('starter-company-' . ($n + 1));
            $company->name = $row['name'];
            $company->slug = Slugs::unique($data, Company::class, $row['name'], $company->uuid);
            $company->place = $row['place'];
            $company->website = $row['website'];
            $company->summary = $row['summary'];
            $data->save($company);
        }

        foreach ($demo['jobs'] as $n => $row) {
            $job = new Job();
            $job->uuid = md5('starter-job-' . ($n + 1));
            $job->title = $row['title'];
            $job->slug = Slugs::unique($data, Job::class, $row['title'], $job->uuid);
            $job->company = $companyIds[$row['company']];
            $job->place = $row['place'];
            $job->employmentType = $row['employmentType'];
            $job->remote = $row['remote'];
            $job->salaryFrom = $row['salaryFrom'];
            $job->salaryTo = $row['salaryTo'];
            $job->description = $row['description'];
            // Saved as working copies only: nothing publishes them through Folio yet. Fixed dates, so the seed gives the same rows every time.
            $job->createdAt = $job->updatedAt = $row['publishedAt'] ?? '2026-10-20 10:00:00';
            $data->save($job);
        }

        $user = new User();
        $user->uuid = md5('starter-user-1');
        $user->email = self::EMPLOYER_EMAIL;
        $user->passwordHash = (new NativePasswordHasher())->hash(self::EMPLOYER_PASSWORD);
        $user->roles = ['employer'];
        $user->name = 'Demo Employer';
        $user->company = $companyIds[0];
        $user->createdAt = '2026-09-01 08:00:00';
        $data->save($user);

        echo 'Demo employer: ' . self::EMPLOYER_EMAIL . ' / ' . self::EMPLOYER_PASSWORD . " (sign in at /employer)\n";
    }
}
