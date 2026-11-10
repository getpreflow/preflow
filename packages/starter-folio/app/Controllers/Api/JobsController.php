<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Jobs\JobFilters;
use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use App\Jobs\JobWriter;
use App\Models\Job;
use App\Models\User;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Http\TokenAuthMiddleware;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Json;
use Preflow\Data\DataManager;
use Preflow\Routing\Attributes\Delete;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Patch;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Route;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/api/jobs')]
final class JobsController
{
    public function __construct(
        private readonly JobSearch $search,
        private readonly JobWriter $writer,
        private readonly JobResource $resource,
        private readonly DataManager $data,
        private readonly Access $access,
    ) {}

    #[Get('/')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        // The same filters, and the same normalising of odd values, as the /jobs page.
        $result = $this->search->search(JobFilters::fromArray($request->getQueryParams()));
        $companies = $this->search->companiesById(array_map(static fn (Job $job): string => (string) $job->company, $result->items));

        return Json::data(
            array_map(fn (Job $job): array => $this->resource->toArray($job, $companies[$job->company] ?? null), $result->items),
            [
                'page' => $result->currentPage,
                'per_page' => $result->perPage,
                'total' => $result->total,
                'last_page' => $result->lastPage,
            ],
        );
    }

    #[Get('/{id}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        // Only a published copy: a draft answers like a job that does not exist.
        $job = $this->search->byId((string) $request->getAttribute('id'));
        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        return Json::data($this->resource->toArray($job, $this->search->company((string) $job->company)));
    }

    #[Post('/')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        $body = $this->body($request);
        $status = $this->status($body);
        $this->mayPublish($status);

        try {
            $job = $this->writer->create($this->resource->toInput($body), $user);
            if ($status === 'published') {
                $job = $this->writer->publish($job, $user);
            }
        } catch (ValidationException $e) {
            throw $this->apiErrors($e);
        }

        return Json::data($this->present($job), status: 201, headers: ['Location' => '/api/jobs/' . $job->uuid]);
    }

    #[Patch('/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        $job = $this->owned($request);
        $body = $this->body($request);
        $status = $this->status($body);
        $this->mayPublish($status);

        try {
            $input = $this->resource->toInput($body);
            if ($input !== []) {
                $job = $this->writer->update($job, $input);
            }
            // Publishing checks the rules too: staff may have saved a draft in Folio that breaks one.
            if ($status === 'published') {
                $job = $this->writer->publish($job, $user);
            } elseif ($status === 'draft') {
                $job = $this->writer->unpublish($job);
            }
        } catch (ValidationException $e) {
            throw $this->apiErrors($e);
        }

        return Json::data($this->present($job));
    }

    #[Delete('/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->writer->delete($this->owned($request));

        return Json::noContent();
    }

    private function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(Authenticatable::class);
        // The middleware only lets a user through; an account without a company has nothing to post for.
        if (!$user instanceof User || $user->company === null) {
            throw new ForbiddenHttpException('This account has no company.');
        }

        return $user;
    }

    /** The job in any status, if it is the caller's company's: 404 when it does not exist, 403 when it is someone else's. */
    private function owned(ServerRequestInterface $request): Job
    {
        $user = $this->user($request);
        $id = (string) $request->getAttribute('id');
        $job = $this->writer->ownedBy($id, $user);
        if ($job !== null) {
            return $job;
        }
        if ($this->data->find(Job::class, $id) === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        throw new ForbiddenHttpException('This job belongs to another company.');
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function status(array $body): ?string
    {
        if (!array_key_exists('status', $body)) {
            return null;
        }
        if (!in_array($body['status'], ['draft', 'published'], true)) {
            throw new ValidationException(new ValidationResult(['status' => ['The status must be draft or published.']]));
        }

        return $body['status'];
    }

    /**
     * Publishing asks for job.publish (config/roles.php gives it to the employer role), before
     * anything is saved: a token acts as its user, so an account that may only post drafts can't
     * publish through the API either. A draft goes through.
     */
    private function mayPublish(?string $status): void
    {
        if ($status === 'published' && !$this->access->can('job.publish')) {
            throw new ForbiddenHttpException('Your account may post drafts; publishing needs a full employer account.');
        }
    }

    private function apiErrors(ValidationException $e): ValidationException
    {
        return new ValidationException(new ValidationResult($this->resource->toApiErrors($e->errors())));
    }

    /**
     * The owner's view: the working copy (with any edits not yet published), its publish state,
     * and when the published copy went out (null while it is a draft).
     *
     * @return array<string, mixed>
     */
    private function present(Job $job): array
    {
        $job->_published_at = $this->search->byId((string) $job->uuid)?->_published_at;

        return $this->resource->toArray($job, $this->search->company((string) $job->company), $this->writer->state($job));
    }
}
