<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use Preflow\Core\Context\Contexts;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeRegistry;
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
        private readonly TypeRegistry $types,
    ) {}

    #[Get('/{slug}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $company = $this->jobs->companyBySlug((string) $request->getAttribute('slug'));
        if ($company === null) {
            return $this->pages->render('_views/company.twig', ['company' => null, 'summary' => null, 'jobs' => []], 404);
        }

        // openJobsOf returns published jobs only: drafts never show on a public page.
        $open = $this->jobs->openJobsOf((string) $company->uuid);

        // The summary is translatable: the page's language, else the next in the type's fallback chain.
        [$summary] = LocaleValue::resolve($company->summary, $this->types->get('company')->locales, Contexts::current()->locale);

        return $this->pages->render('_views/company.twig', [
            'company' => $company,
            'summary' => $summary,
            'jobs' => array_map(fn ($job): array => $this->resource->toArray($job, $company), $open),
        ]);
    }
}
