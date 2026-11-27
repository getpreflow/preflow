<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\Job;
use Preflow\Core\Config\Config;

/**
 * How a job looks on the site's pages: the job cards, the job page and the company page use this
 * array, so a field is named the same everywhere. The JSON API reads jobs with the core read class
 * instead (App\Controllers\Api\JobsController); it takes only url() from here.
 */
final class JobResource
{
    private readonly string $baseUrl;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim((string) $config->get('app.url', ''), '/');
    }

    /** The job's public page, as a full address. */
    public function url(?string $slug): string
    {
        return $this->baseUrl . '/jobs/' . $slug;
    }

    /** @return array<string, mixed> */
    public function toArray(Job $job, ?Company $company): array
    {
        $data = [
            'id' => $job->uuid,
            'slug' => $job->slug,
            'title' => $job->title,
            'company' => $company === null ? null : [
                'id' => $company->uuid,
                'slug' => $company->slug,
                'name' => $company->name,
            ],
            'place' => $job->place,
            'employment_type' => $job->employmentType,
            'remote' => $job->remote,
            'salary' => $job->salaryFrom === null && $job->salaryTo === null ? null : [
                'from' => $job->salaryFrom,
                'to' => $job->salaryTo,
                'currency' => Job::CURRENCY,
            ],
            // Markdown source: the client decides how to render it.
            'description' => $job->description,
        ];
        $data['published_at'] = $job->publishedAt === null
            ? null
            : (new \DateTimeImmutable($job->publishedAt))->format(\DateTimeInterface::ATOM);
        $data['url'] = $this->url($job->slug);

        return $data;
    }
}
