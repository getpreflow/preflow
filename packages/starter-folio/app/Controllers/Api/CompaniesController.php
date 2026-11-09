<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use App\Models\Company;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Json;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/api/companies')]
final class CompaniesController
{
    public function __construct(
        private readonly JobSearch $jobs,
        private readonly JobResource $resource,
    ) {}

    #[Get('/')]
    public function index(): ResponseInterface
    {
        return Json::data(array_map(fn (Company $company): array => $this->present($company), $this->jobs->companies()));
    }

    #[Get('/{slug}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $company = $this->jobs->companyBySlug((string) $request->getAttribute('slug'));
        if ($company === null) {
            throw new NotFoundHttpException('Company not found.');
        }

        $data = $this->present($company);
        $data['jobs'] = array_map(
            fn ($job): array => $this->resource->toArray($job, $company),
            $this->jobs->openJobsOf((string) $company->uuid),
        );

        return Json::data($data);
    }

    /** @return array<string, mixed> */
    private function present(Company $company): array
    {
        return [
            'id' => $company->uuid,
            'slug' => $company->slug,
            'name' => $company->name,
            'place' => $company->place,
            'website' => $company->website,
            'summary' => $company->summary,
            'open_jobs' => $this->jobs->openJobCount((string) $company->uuid),
        ];
    }
}
