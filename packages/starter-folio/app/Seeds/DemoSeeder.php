<?php

declare(strict_types=1);

namespace App\Seeds;

use App\Jobs\Slugs;
use App\Models\Company;
use App\Models\Job;
use App\Models\Staff;
use App\Models\User;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Core\Config\Config;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Media\Image\ImageBackends;
use Preflow\Media\MediaLibrary;

/**
 * Seven fictional companies with logos, forty jobs (34 published through Folio, 6 drafts), one
 * demo employer and two staff accounts, so a fresh install has something to show. Ids and dates
 * are fixed: running it again updates the same rows and publishes only what changed.
 */
final class DemoSeeder
{
    /** @var array<string, array{string, list<string>}> email => [password, roles] */
    private const array STAFF = [
        'admin@example.com' => ['demo-admin', ['admin']],
        'editor@example.com' => ['demo-editor', ['editor']],
    ];

    private const string EMPLOYER_EMAIL = 'employer@example.com';
    private const string EMPLOYER_PASSWORD = 'demo-employer';

    private const string LOGOS = __DIR__ . '/../../resources/seed/logos';

    public function __construct(
        private readonly Config $config,
        private readonly Publisher $publisher,
        private readonly MediaLibrary $media,
    ) {}

    public function run(DataManager $data): void
    {
        // The demo passwords are public (they are printed below), so production must never get them.
        if ((int) $this->config->get('app.debug', 0) === 0) {
            echo "Demo data is only seeded while APP_DEBUG is on.\n";

            return;
        }

        $demo = require __DIR__ . '/demo-data.php';

        // Without GD or Imagick the media library can't make image sizes, so the demo goes without
        // logos rather than failing. STARTER_NO_IMAGES=1 does the same on a PHP that has them.
        $logos = ImageBackends::detect() !== null && getenv('STARTER_NO_IMAGES') !== '1';
        if (!$logos) {
            echo "Logos skipped: no image library.\n";
        }

        $companyIds = [];
        foreach ($demo['companies'] as $n => $row) {
            $uuid = $companyIds[$n] = md5('starter-company-' . ($n + 1));
            // Loaded first, so running the seed again keeps the logo it imported the first time.
            $company = $data->find(Company::class, $uuid) ?? new Company();
            $company->uuid = $uuid;
            $company->name = $row['name'];
            $company->slug = Slugs::unique($data, Company::class, $row['name'], $uuid);
            $company->place = $row['place'];
            $company->website = $row['website'];
            $company->summary = $row['summary'];
            $logo = self::LOGOS . '/' . $company->slug . '.png';
            if ($logos && is_file($logo) && ($company->logo === null || $this->media->find($company->logo) === null)) {
                $company->logo = $this->media->importFile($logo, basename($logo), 'seed')->id;
            }
            $data->save($company);
            $this->publish($data, 'company', $uuid);
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
            // Fixed dates, so the seed gives the same rows every time.
            $job->createdAt = $job->updatedAt = $row['publishedAt'] ?? '2026-10-20 10:00:00';
            $data->save($job);

            // A draft stays a working copy: saved, never published.
            if ($row['status'] !== 'published') {
                continue;
            }
            $snapshot = $this->publish($data, 'job', $job->uuid);
            if ($snapshot !== null) {
                // Folio stamps the time of publishing. The demo dates its jobs instead, so the
                // list has the same order on every install.
                $snapshot->set('_published_at', (new \DateTimeImmutable($row['publishedAt']))->format(DATE_ATOM));
                $data->saveType($snapshot, false);
            }
        }

        $hasher = new NativePasswordHasher();
        $n = 0;
        foreach (self::STAFF as $email => [$password, $roles]) {
            $staff = new Staff();
            $staff->uuid = md5('starter-staff-' . ++$n);
            $staff->email = $email;
            $staff->passwordHash = $hasher->hash($password);
            $staff->roles = $roles;
            $staff->name = 'Demo ' . ucfirst($roles[0]);
            $staff->createdAt = '2026-09-01 08:00:00';
            $data->save($staff);
        }

        $user = new User();
        $user->uuid = md5('starter-user-1');
        $user->email = self::EMPLOYER_EMAIL;
        $user->passwordHash = $hasher->hash(self::EMPLOYER_PASSWORD);
        $user->roles = ['employer'];
        $user->name = 'Demo Employer';
        $user->company = $companyIds[0];
        $user->createdAt = '2026-09-01 08:00:00';
        $data->save($user);

        echo 'Demo employer: ' . self::EMPLOYER_EMAIL . ' / ' . self::EMPLOYER_PASSWORD . " (sign in at /employer)\n";
        foreach (self::STAFF as $email => [$password, $roles]) {
            echo 'Demo ' . $roles[0] . ': ' . $email . ' / ' . $password . " (sign in at /folio)\n";
        }
    }

    /**
     * Publishes the working copy through Folio, unless the published copy already matches it:
     * a rerun then adds no revision. Returns the new published copy, or null when nothing changed.
     */
    private function publish(DataManager $data, string $type, string $id): ?DynamicRecord
    {
        $record = $data->findType($type, $id);
        if ($record === null || $this->publisher->state($record) === PublishState::Published) {
            return null;
        }

        return $this->publisher->publish($record, 'seed');
    }
}
