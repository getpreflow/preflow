<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\Job;
use Preflow\Core\Config\Config;

/**
 * The one shape a job has outside the database: the job cards and the JSON API both use it,
 * so a field is named the same everywhere.
 */
final class JobResource
{
    private readonly string $baseUrl;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim((string) $config->get('app.url', ''), '/');
    }

    /**
     * @param bool $withStatus employers see the status of their own jobs; the public never does
     * @return array<string, mixed>
     */
    public function toArray(Job $job, ?Company $company, bool $withStatus = false): array
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
        if ($withStatus) {
            $data['status'] = $job->status;
        }
        $data['published_at'] = $job->publishedAt === null
            ? null
            : (new \DateTimeImmutable($job->publishedAt))->format(\DateTimeInterface::ATOM);
        $data['url'] = $this->baseUrl . '/jobs/' . $job->slug;

        return $data;
    }
}
