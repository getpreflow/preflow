<?php

declare(strict_types=1);

namespace App\Seeds;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\Publisher;

/**
 * The texts staff edit in Folio under Pages (config/models/{jobs_page,job_page,company_page}.json),
 * published with a first English version. Production needs them too, so this runs without debug
 * and creates no accounts. A part that exists is skipped: staff may have changed it since.
 */
final class ContentSeeder
{
    /** @var array<string, array<string, string>> type => field values */
    private const array PARTS = [
        'jobs_page' => [
            'intro' => '<p>Open positions from small companies across Europe. Search by title, or narrow the list by place, type and remote work.</p>',
        ],
        'job_page' => [
            'cta_title' => 'Interested?',
            'cta_text' => '<p>Write to the company directly and mention where you saw the job. Most teams answer within a week.</p>',
        ],
        'company_page' => [
            'before_jobs' => '<p>Every job below is open now. Closed jobs are taken down, so there is nothing stale to apply to.</p>',
        ],
    ];

    public function __construct(private readonly Publisher $publisher) {}

    public function run(DataManager $data): void
    {
        foreach (self::PARTS as $type => $values) {
            if ($data->findType($type, $type) !== null) {
                continue;
            }
            // A singleton's one record has the type key as its id.
            $record = DynamicRecord::fromArray($data->getTypeRegistry()->get($type), ['uuid' => $type] + $values);
            $this->publisher->publish($record, 'seed');
        }
    }
}
