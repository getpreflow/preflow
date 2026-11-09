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
    /** API name => Job property. Only the fields a client may write. */
    private const array API_FIELDS = [
        'title' => 'title',
        'place' => 'place',
        'employment_type' => 'employmentType',
        'remote' => 'remote',
        'salary_from' => 'salaryFrom',
        'salary_to' => 'salaryTo',
        'description' => 'description',
    ];

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

    /**
     * The API names fields in snake_case; JobWriter takes the property names. Keys the writer does
     * not know are dropped there, so this only has to rename.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function toInput(array $body): array
    {
        $input = [];
        foreach (self::API_FIELDS as $api => $property) {
            if (array_key_exists($api, $body)) {
                $input[$property] = $body[$api];
            }
        }

        return $input;
    }

    /**
     * The same rename the other way, for validation errors.
     *
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    public function toApiErrors(array $errors): array
    {
        $property = array_flip(self::API_FIELDS);
        $renamed = [];
        foreach ($errors as $field => $messages) {
            $renamed[$property[$field] ?? $field] = $messages;
        }

        return $renamed;
    }
}
