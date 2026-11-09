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

#[Route('/jobs')]
final class JobController
{
    public function __construct(
        private readonly JobSearch $jobs,
        private readonly JobResource $resource,
        private readonly PageResponder $pages,
    ) {}

    #[Get('/{slug}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        // bySlug only finds published jobs, so a draft looks the same as a job that never existed.
        $job = $this->jobs->bySlug((string) $request->getAttribute('slug'));
        if ($job === null) {
            // A controller can set the status; a file page can't, which is why this is not a .twig route.
            return $this->pages->render('_views/job.twig', ['job' => null, 'company' => null, 'moreFromCompany' => []], 404);
        }

        $company = $this->jobs->company((string) $job->company);
        $others = $this->jobs->openJobsOf((string) $job->company, $job->uuid, 4);

        return $this->pages->render('_views/job.twig', [
            'job' => $this->resource->toArray($job, $company),
            'company' => $company,
            'moreFromCompany' => array_map(fn ($other): array => $this->resource->toArray($other, $company), $others),
        ]);
    }
}
