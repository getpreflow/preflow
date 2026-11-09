<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Models\Company;
use App\Models\Job;
use App\Models\Staff;
use App\Models\User;
use App\Seeds\DemoSeeder;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\DevTools\Console;
use Preflow\Folio\Publishing\PublishedModels;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Media\MediaLibrary;
use Tests\Support\StarterApp;

final class SeedTest extends TestCase
{
    use StarterApp;

    protected function tearDown(): void
    {
        putenv('STARTER_NO_IMAGES');
        unset($_ENV['STARTER_NO_IMAGES']);
        $this->removeStarter();
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    /** Working copies: every record, published or not. */
    private function total(string $class, array $where = []): int
    {
        $query = $this->data()->query($class);
        foreach ($where as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get()->total();
    }

    /** Published copies: what the public sees. */
    private function published(string $class, array $where = []): int
    {
        $query = $this->container()->get(PublishedModels::class)->query($class);
        foreach ($where as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get()->total();
    }

    /** @return list<\Preflow\Data\DynamicRecord> the published news posts */
    private function publishedNews(): array
    {
        return $this->container()->get(Publisher::class)->query('news')->get()->items();
    }

    /** @return array<string, string> job uuid => draft|published|changed */
    private function states(): array
    {
        return array_map(
            static fn ($state): string => $state->value,
            $this->container()->get(Publisher::class)->states('job'),
        );
    }

    /** @return array{int, string} exit code and output of `preflow db:seed` */
    private function seed(): array
    {
        $previous = getcwd();
        chdir($this->starterRoot);
        ob_start();
        try {
            $code = (new Console($this->starterRoot))->run(['preflow', 'db:seed']);
        } finally {
            $output = (string) ob_get_clean();
            chdir($previous ?: '/');
        }

        return [$code, $output];
    }

    public function test_demo_seed_counts(): void
    {
        $this->bootStarter();

        $this->assertSame(7, $this->total(Company::class));
        $this->assertSame(7, $this->published(Company::class));
        $this->assertSame(40, $this->total(Job::class));
        $this->assertSame(34, $this->published(Job::class));
        $this->assertSame(1, $this->total(User::class));
        $this->assertSame(2, $this->total(Staff::class));
        $this->assertCount(3, $this->publishedNews());
    }

    public function test_the_drafts_and_the_places_are_as_planned(): void
    {
        $this->bootStarter();

        $northwind = $this->data()->query(Company::class)->where('name', 'Northwind Labs')->first();
        $this->assertNotNull($northwind);
        $states = $this->states();
        $this->assertCount(40, $states);
        $this->assertSame(6, count(array_keys($states, 'draft', true)));
        $this->assertSame(34, count(array_keys($states, 'published', true)));
        $northwindDrafts = array_filter(
            $this->data()->query(Job::class)->where('company', $northwind->uuid)->get()->items(),
            static fn (Job $job): bool => $states[$job->uuid] === 'draft',
        );
        $this->assertCount(4, $northwindDrafts);

        foreach (['Berlin', 'Zurich', 'Vienna', 'Amsterdam', 'Lisbon', 'London'] as $place) {
            $this->assertGreaterThan(0, $this->published(Job::class, ['place' => $place]), $place);
        }
        // The planned dates, not the time the seed ran: the list has the same order on every install.
        foreach ($this->container()->get(PublishedModels::class)->query(Job::class)->get()->items() as $job) {
            $this->assertGreaterThanOrEqual('2026-09-01', $job->postedOn);
            $this->assertLessThanOrEqual('2026-11-05', $job->postedOn);
        }
    }

    public function test_every_filter_value_has_at_least_three_published_jobs(): void
    {
        $this->bootStarter();

        foreach (['full_time', 'part_time', 'contract', 'internship'] as $type) {
            $this->assertGreaterThanOrEqual(3, $this->published(Job::class, ['employmentType' => $type]), $type);
        }
        foreach (['no', 'hybrid', 'full'] as $remote) {
            $this->assertGreaterThanOrEqual(3, $this->published(Job::class, ['remote' => $remote]), $remote);
        }
    }

    public function test_seed_twice_keeps_counts(): void
    {
        $this->bootStarter();
        $assets = count($this->container()->get(MediaLibrary::class)->all());
        $revisions = count($this->container()->get(Publisher::class)->revisions('job', md5('starter-job-2')));

        [$code, $output] = $this->seed();

        $this->assertSame(0, $code, $output);
        $this->assertSame(7, $this->total(Company::class));
        $this->assertSame(40, $this->total(Job::class));
        $this->assertSame(34, $this->published(Job::class));
        $this->assertSame(1, $this->total(User::class));
        $this->assertSame(2, $this->total(Staff::class));
        $this->assertCount(3, $this->publishedNews());
        $this->assertSame(3, $this->data()->queryType('news')->get()->total());
        $this->assertSame($assets, count($this->container()->get(MediaLibrary::class)->all()), 'the logos and news images are imported once');
        $this->assertSame(
            $revisions,
            count($this->container()->get(Publisher::class)->revisions('job', md5('starter-job-2'))),
            'an unchanged job is not published again',
        );
        $this->assertSame('published', $this->states()[md5('starter-job-2')]);
    }

    public function test_the_demo_seed_imports_the_logos(): void
    {
        $this->bootStarter();

        $media = $this->container()->get(MediaLibrary::class);
        foreach ($this->container()->get(PublishedModels::class)->query(Company::class)->get()->items() as $company) {
            $this->assertNotNull($company->logo, $company->name);
            $this->assertSame('image/png', $media->find($company->logo)?->mime, $company->name);
        }
    }

    public function test_the_demo_seed_imports_the_news_images(): void
    {
        $this->bootStarter();

        $media = $this->container()->get(MediaLibrary::class);
        foreach ($this->publishedNews() as $post) {
            $asset = $media->find((string) $post->get('image'));
            $this->assertSame('image/png', $asset?->mime, (string) $post->get('title'));
            $this->assertNotSame('', $asset->altFor('en', 'en'), 'an image with a description');
        }
    }

    public function test_seed_without_image_library_succeeds(): void
    {
        // What the seeder sees on a PHP without GD and Imagick.
        $this->setEnv('STARTER_NO_IMAGES', '1');
        $this->bootStarter();

        [$code, $output] = $this->seed();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Logos and news images skipped: no image library.', $output);
        $this->assertSame([], $this->container()->get(MediaLibrary::class)->all());
        $this->assertSame(34, $this->published(Job::class));
        $this->assertSame(7, $this->published(Company::class));
        $this->assertCount(3, $this->publishedNews(), 'the news is seeded without its images');
    }

    public function test_content_seed_runs_without_debug_and_creates_no_accounts(): void
    {
        $this->bootStarter(debug: false);

        $publisher = $this->container()->get(Publisher::class);
        foreach (['jobs_page', 'job_page', 'company_page'] as $part) {
            $this->assertNotNull($publisher->snapshot($part, $part), $part . ' is published');
        }
        $this->assertNotNull($publisher->snapshot('page', md5('starter-page-1')), 'the home page is published');
        $this->assertSame([], $this->publishedNews(), 'news is demo content');
        $this->assertSame(0, $this->total(User::class));
        $this->assertSame(0, $this->total(Staff::class));
        $this->assertSame(0, $this->total(Job::class));

        // The content seeder skips what exists: staff may have changed the text since.
        $record = $this->data()->findType('jobs_page', 'jobs_page');
        $record->set('intro', '<p>Our own words.</p>');
        $publisher->publish($record, 'test');
        [$code, $output] = $this->seed();
        $this->assertSame(0, $code, $output);
        $this->assertSame('<p>Our own words.</p>', $publisher->snapshot('jobs_page', 'jobs_page')?->get('intro'));
    }

    public function test_the_demo_logins_work_with_the_printed_passwords(): void
    {
        $this->bootStarter();

        $user = $this->data()->query(User::class)->where('email', 'employer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(password_verify('demo-employer', $user->passwordHash));
        $this->assertSame('Northwind Labs', $this->data()->find(Company::class, $user->company)?->name);

        foreach (['admin@example.com' => ['demo-admin', ['admin']], 'editor@example.com' => ['demo-editor', ['editor']]] as $email => [$password, $roles]) {
            $staff = $this->data()->query(Staff::class)->where('email', $email)->first();
            $this->assertNotNull($staff, $email);
            $this->assertTrue(password_verify($password, $staff->passwordHash), $email);
            $this->assertSame($roles, $staff->roles);
        }

        [, $output] = $this->seed();
        $this->assertStringContainsString('employer@example.com / demo-employer', $output);
        $this->assertStringContainsString('admin@example.com / demo-admin', $output);
        $this->assertStringContainsString('editor@example.com / demo-editor', $output);
    }

    public function test_migrate_indexes_the_company_column(): void
    {
        $this->bootStarter();
        $pdo = $this->data()->driver('default')->pdo();
        $indexes = static fn (): array => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_jobs%_company'")->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertEqualsCanonicalizing(['idx_jobs_company', 'idx_jobs__published_company'], $indexes());
        $this->assertSame(0, $this->console('migrate'), 'a second migrate finds them and does nothing');
        $this->assertCount(2, $indexes());
    }

    public function test_demo_seed_refuses_without_debug(): void
    {
        $this->bootStarter(debug: false);

        $this->assertSame(0, $this->total(Job::class));

        ob_start();
        $this->container()->make(DemoSeeder::class)->run($this->data());
        $output = ob_get_clean();

        $this->assertStringContainsString('Demo data is only seeded while APP_DEBUG is on.', $output);
        $this->assertSame(0, $this->total(Job::class));
        $this->assertSame(0, $this->total(User::class));
        $this->assertSame(0, $this->total(Staff::class));
    }
}
