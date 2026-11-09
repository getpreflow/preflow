<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Route;
use Preflow\View\PageResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/companies')]
final class CompanyController
{
    public function __construct(
        private readonly JobSearch $jobs,
        private readonly JobResource $resource,
        private readonly PageResponder $pages,
    ) {}

    #[Get('/{slug}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $company = $this->jobs->companyBySlug((string) $request->getAttribute('slug'));
        if ($company === null) {
            return $this->pages->render('_views/company.twig', ['company' => null, 'jobs' => []], 404);
        }

        // openJobsOf returns published jobs only: drafts never show on a public page.
        $open = $this->jobs->openJobsOf((string) $company->uuid);

        return $this->pages->render('_views/company.twig', [
            'company' => $company,
            'jobs' => array_map(fn ($job): array => $this->resource->toArray($job, $company), $open),
        ]);
    }
}
