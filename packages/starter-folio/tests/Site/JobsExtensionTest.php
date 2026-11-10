<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Folio\Jobs\Types\CompanyAdmin;
use App\Models\Company;
use App\Models\Job;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\PublishedModels;
use Tests\Support\StarterApp;

/** The site's Folio extension (app/Folio/Jobs): open jobs per company in the list, a drafts notice on the dashboard. */
final class JobsExtensionTest extends TestCase
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

    /** @return array<string, string> */
    private function admin(): array
    {
        return ['Cookie' => $this->cookieHeader($this->signInStaff('admin@example.com', 'demo-admin'))];
    }

    /** The demo seed leaves some jobs as drafts: publish them all, then unpublish $drafts of them. */
    private function leaveDrafts(int $drafts): void
    {
        $container = $this->app()->container();
        $data = $container->get(DataManager::class);
        $publisher = $container->get(Publisher::class);
        $jobs = array_values($data->query(Job::class)->orderBy('uuid')->get()->items());
        foreach ($jobs as $i => $job) {
            $record = $data->findType('job', (string) $job->uuid);
            $i < $drafts ? $publisher->unpublish('job', (string) $job->uuid) : $publisher->publish($record, 'admin@example.com');
        }
    }

    /** The pattern of Lumen Harbor's row: its name, its place, then the "Open jobs" cell. */
    private function openJobsCell(int $open): string
    {
        return '#>Lumen Harbor</a></td><td>[^<]*</td><td>' . $open . '</td>#';
    }

    public function test_company_list_shows_open_jobs(): void
    {
        $container = $this->container();
        $company = $container->get(DataManager::class)->query(Company::class)->where('name', 'Lumen Harbor')->first();
        $published = fn (): int => $container->get(PublishedModels::class)->query(Job::class)->where('company', $company->uuid)->limit(1)->get()->total();
        $open = $published();
        $this->assertGreaterThan(1, $open);

        $page = $this->get('/folio/company', $this->admin());

        $page->assertOk()->assertSee('Open jobs');
        $this->assertMatchesRegularExpression($this->openJobsCell($open), $page->body());

        // A draft is not an open job, although the admin's preview shows it to staff.
        $job = $container->get(PublishedModels::class)->query(Job::class)->where('company', $company->uuid)->first();
        $container->get(Publisher::class)->unpublish('job', (string) $job->uuid);

        $this->assertMatchesRegularExpression($this->openJobsCell($open - 1), $this->get('/folio/company', $this->admin())->body());
    }

    public function test_dashboard_shows_drafts_notice_when_jobs_are_unpublished(): void
    {
        $this->leaveDrafts(0);
        $this->get('/folio', $this->admin())->assertOk()->assertNotSee('is a draft')->assertNotSee('are drafts');

        $this->leaveDrafts(1);
        $this->get('/folio', $this->admin())->assertSee('<a href="/folio/job">1 job is a draft</a>');

        $this->leaveDrafts(2);
        $this->get('/folio', $this->admin())->assertSee('2 jobs are drafts');
    }

    public function test_notice_links_to_the_jobs_where_the_admin_lives(): void
    {
        $this->leaveDrafts(1);
        file_put_contents($this->starterRoot . '/config/folio.php', "<?php\n\nreturn ['path' => '/cms/'];\n");

        $notice = $this->app()->container()->get(CompanyAdmin::class)->drafts();

        $this->assertSame('<p><a href="/cms/job">1 job is a draft</a></p>', $notice);
    }

    public function test_notice_prints_no_stored_text(): void
    {
        $data = $this->container()->get(DataManager::class);
        $job = $data->query(Job::class)->first();
        $job->title = '<script>x</script>';
        $data->save($job);
        $this->leaveDrafts(0);
        $this->container()->get(Publisher::class)->unpublish('job', (string) $job->uuid);

        $body = $this->get('/folio', $this->admin())->assertSee('1 job is a draft')->body();

        $this->assertStringNotContainsString('<script>x', $body);
    }
}
