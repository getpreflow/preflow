<?php

declare(strict_types=1);

namespace App\Folio\Jobs\Types;

use App\Models\Job;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\DashboardNotice;
use Preflow\Folio\Extension\ListColumn;
use Preflow\Folio\Publishing\Publisher;

/**
 * The company list shows how many jobs of each company the public sees (its published copies),
 * and the dashboard tells staff how many jobs are still drafts. Both read the copies through
 * Publisher: inside the admin, PublishedModels would show the working copies (the preview).
 */
#[FolioType('company')]
final class CompanyAdmin
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly DataManager $data,
        private readonly Application $app,
    ) {}

    #[ListColumn('Open jobs')]
    public function openJobs(DynamicRecord $record): string
    {
        $jobs = $this->publisher->query('job')->where('company', (string) $record->getId());

        return (string) $jobs->limit(1)->get()->total();
    }

    #[DashboardNotice]
    public function drafts(): ?string
    {
        $all = $this->data->query(Job::class)->limit(1)->get()->total();
        $drafts = $all - $this->publisher->query('job')->limit(1)->get()->total();
        if ($drafts < 1) {
            return null;
        }

        $text = $drafts === 1 ? '1 job is a draft' : "{$drafts} jobs are drafts";
        $admin = rtrim((string) ($this->app->configFile('folio')['path'] ?? '/folio'), '/');

        // The notice is printed as HTML: the configured path is escaped like any other value.
        return '<p><a href="' . htmlspecialchars($admin . '/job', ENT_QUOTES, 'UTF-8') . '">' . $text . '</a></p>';
    }
}
