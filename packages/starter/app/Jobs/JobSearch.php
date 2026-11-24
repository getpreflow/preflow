<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\Job;
use Preflow\Data\DataManager;
use Preflow\Data\PaginatedResult;
use Preflow\Data\SortDirection;

/** Everything the public pages read. Only published jobs ever leave this class. */
final class JobSearch
{
    /** Jobs per page of search(), unless it is asked for another number. */
    public const int PER_PAGE = 12;

    public function __construct(private readonly DataManager $data) {}

    public function search(JobFilters $f, int $perPage = self::PER_PAGE): PaginatedResult
    {
        return $this->matching($f)->orderBy('publishedAt', SortDirection::Desc)->paginate($perPage, $f->page);
    }

    /** The last page search() has for $f, counted without loading the jobs (1 when none match). */
    public function lastPage(JobFilters $f, int $perPage = self::PER_PAGE): int
    {
        // total() ignores the limit, so this counts in the database.
        return max(1, (int) ceil($this->matching($f)->limit(1)->get()->total() / $perPage));
    }

    /** @return list<Job> */
    public function latest(int $n = 6): array
    {
        return array_values($this->published()->orderBy('publishedAt', SortDirection::Desc)->limit($n)->get()->items());
    }

    /** @return list<string> */
    public function places(): array
    {
        $places = [];
        foreach ($this->published()->get()->items() as $job) {
            if ($job->place !== null && $job->place !== '') {
                $places[$job->place] = true;
            }
        }
        $places = array_keys($places);
        sort($places);

        return $places;
    }

    public function bySlug(string $slug): ?Job
    {
        return $this->published()->where('slug', $slug)->first();
    }

    public function company(string $uuid): ?Company
    {
        return $this->data->find(Company::class, $uuid);
    }

    public function companyBySlug(string $slug): ?Company
    {
        return $this->data->query(Company::class)->where('slug', $slug)->first();
    }

    /** @return list<Job> */
    public function openJobsOf(string $companyUuid, ?string $exceptJob = null, int $limit = 50): array
    {
        $query = $this->published()->where('company', $companyUuid);
        if ($exceptJob !== null) {
            $query->where('uuid', '!=', $exceptJob);
        }

        return array_values($query->orderBy('publishedAt', SortDirection::Desc)->limit($limit)->get()->items());
    }

    public function openJobCount(string $companyUuid): int
    {
        // One count per company: fine for a short list of companies. For a long one, count
        // every company in a single grouped query (or keep a counter on the company) instead.
        // total() ignores the limit, so this counts in the database without loading the jobs.
        return $this->published()->where('company', $companyUuid)->limit(1)->get()->total();
    }

    /** @return list<Company> */
    public function companies(): array
    {
        return array_values($this->data->query(Company::class)->orderBy('name')->get()->items());
    }

    /**
     * The companies of a list in one query, so a list of jobs does not read one company per row.
     *
     * @param list<string> $uuids
     * @return array<string, Company> keyed by uuid
     */
    public function companiesById(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids, static fn (string $id): bool => $id !== '')));
        if ($uuids === []) {
            return [];
        }

        $companies = [];
        foreach ($this->data->query(Company::class)->byIds($uuids)->get()->items() as $company) {
            $companies[$company->uuid] = $company;
        }

        return $companies;
    }

    /** The published jobs that pass $f's filters (the page is search()'s). */
    private function matching(JobFilters $f): \Preflow\Data\QueryBuilder
    {
        $query = $this->published();
        if ($f->q !== '') {
            $query->searchLoose($f->q, ['title', 'description']);
        }
        if ($f->place !== null) {
            $query->where('place', $f->place);
        }
        if ($f->type !== null) {
            $query->where('employmentType', $f->type);
        }
        if ($f->remote !== null) {
            $query->where('remote', $f->remote);
        }

        return $query;
    }

    private function published(): \Preflow\Data\QueryBuilder
    {
        return $this->data->query(Job::class)->where('status', 'published');
    }
}
