<?php

declare(strict_types=1);

namespace App\Seeds;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\Publisher;

/**
 * The home page and the texts staff edit in Folio under Pages
 * (config/models/{page,jobs_page,job_page,company_page}.json), published with a first English
 * version. Production needs them too, so this runs without debug and creates no accounts. A record
 * that exists is skipped: staff may have changed it since.
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

    /** The home page's fixed id, so a rerun finds it. */
    public const string HOME = '2846b3d6afb99ab42774b38439d61197'; // md5('starter-page-1')

    public function __construct(private readonly Publisher $publisher) {}

    public function run(DataManager $data): void
    {
        if ($data->findType('page', self::HOME) === null) {
            $this->publisher->publish(DynamicRecord::fromArray($data->getTypeRegistry()->get('page'), self::home()), 'seed');
        }

        foreach (self::PARTS as $type => $values) {
            if ($data->findType($type, $type) !== null) {
                continue;
            }
            // A singleton's one record has the type key as its id.
            $record = DynamicRecord::fromArray($data->getTypeRegistry()->get($type), ['uuid' => $type] + $values);
            $this->publisher->publish($record, 'seed');
        }
    }

    /**
     * The page Folio serves at / (its slug is the type's frontend "home"). Its sections are blocks;
     * the uids are fixed so the seed gives the same page every time.
     *
     * @return array<string, mixed>
     */
    private static function home(): array
    {
        return [
            'uuid' => self::HOME,
            'title' => 'Home',
            'slug' => 'home',
            'sections' => [
                [
                    '_uid' => 'a1000000000000a1',
                    '_block' => 'hero',
                    'headline' => 'Preflow Jobs',
                    'intro' => 'A small job board to read, run and copy. Staff build this page from blocks in Folio; the job cards come from the app.',
                    'cta_label' => 'Browse jobs',
                    'cta_url' => '/jobs',
                ],
                [
                    '_uid' => 'a1000000000000a2',
                    '_block' => 'text',
                    'body' => '<ul>'
                        . '<li><a href="/jobs">Browse and search jobs</a>: the public site.</li>'
                        . '<li><a href="/employer">Employer area</a>: sign in as an employer to post jobs.</li>'
                        . '<li><a href="/api/jobs">JSON API</a>: the same jobs for other programs.</li>'
                        . '<li><a href="/folio">Folio</a>: where staff edit this page, the news and the jobs.</li>'
                        . '</ul>',
                ],
                ['_uid' => 'a1000000000000a3', '_block' => 'latest_jobs', 'title' => 'Latest jobs', 'count' => 6],
                ['_uid' => 'a1000000000000a4', '_block' => 'latest_news', 'title' => 'News', 'count' => 3],
            ],
            // Folio's "SEO & sharing" section: the page has no description field of its own.
            '_seo' => ['description' => ['en' => 'A small job board built with Preflow: browse jobs, post jobs as an employer, or read them from the JSON API.']],
        ];
    }
}
