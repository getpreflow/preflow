<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Jobs\JobFilters;
use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use App\Models\Job;
use App\Models\User;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Http\TokenAuthMiddleware;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Json;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Records\RecordInput;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\Slugs;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
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

/**
 * The jobs API: the public reads published jobs, an employer writes their company's jobs with a
 * token. A job is read with the core read class (RecordOutput: "_id" and the fields, by their
 * names) and written with the core write class (RecordInput), then saved with saveType(), which
 * runs the same rules as the employer area's form.
 *
 * What this controller adds to the core: who may write (the job's company), what the server sets
 * (company, slug, dates) and the job's page ("_url").
 */
#[Route('/api/jobs')]
final class JobsController
{
    /** The fields a client writes. The server sets the others; a body that names one is refused. */
    private const array WRITABLE = ['title', 'place', 'employmentType', 'remote', 'salaryFrom', 'salaryTo', 'description', 'status'];

    public function __construct(
        private readonly JobSearch $search,
        private readonly JobResource $resource,
        private readonly DataManager $data,
        private readonly TypeRegistry $types,
        private readonly RecordOutput $output,
        private readonly RecordInput $input,
    ) {}

    #[Get('/')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        // The same filters, and the same normalising of odd values, as the /jobs page.
        $result = $this->search->search(JobFilters::fromArray($request->getQueryParams()));

        return Json::data(
            array_map(fn (Job $job): array => $this->present($this->record($job)), $result->items),
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
        $job = $this->data->findIn($this->job(), (string) $request->getAttribute('id'));
        // A draft answers like a job that does not exist.
        if ($job === null || $job->get('status') !== 'published') {
            throw new NotFoundHttpException('Job not found.');
        }

        return Json::data($this->present($job));
    }

    #[Post('/')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        $body = $this->body($request);
        $this->refuseServerKeys($body, new: true);

        // The body onto a new job (refused: 422), then what the server sets.
        $job = $this->input->create($this->job(), $body);
        $job->set('uuid', bin2hex(random_bytes(16)));
        $job->set('company', $user->company);
        // Made from the title once; editing the title later keeps the address people may have shared.
        $job->set('slug', Slugs::unique($this->data, Job::class, (string) $job->get('title')));
        $job->set('status', $job->get('status') ?? 'draft');
        $job->set('createdAt', self::now());
        $this->save($job);

        return Json::data($this->present($job), status: 201, headers: ['Location' => '/api/jobs/' . $job->getId()]);
    }

    #[Patch('/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->owned($request);
        $body = $this->body($request);
        $this->refuseServerKeys($body, new: false);

        // Only what the body names changes; {"status": "draft"} takes a job off the site.
        $this->input->update($job, $body);
        $this->save($job);

        return Json::data($this->present($job));
    }

    #[Delete('/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->data->deleteIn($this->job(), (string) $this->owned($request)->getId());

        return Json::noContent();
    }

    /** The job type, as the core read and write classes take it (App\Models\Job's `type: 'job'`). */
    private function job(): TypeDefinition
    {
        return $this->types->get('job');
    }

    /** A job the search found, as the record the read class reads: the same values. */
    private function record(Job $job): DynamicRecord
    {
        return DynamicRecord::fromArray($this->job(), $job->toArray());
    }

    /** @return array<string, mixed> the core read output, and the job's page on the site */
    private function present(DynamicRecord $job): array
    {
        return $this->output->toArray($job) + ['_url' => $this->resource->url($job->get('slug'))];
    }

    /**
     * Saves the job with the type's rules and the model's own (the salary range), as the employer
     * area's form does. The first publish dates the job; later ones keep the date, so unpublishing
     * and publishing again does not move it to the top of the list.
     *
     * @throws ValidationException nothing saved (422)
     */
    private function save(DynamicRecord $job): void
    {
        if ($job->get('status') === 'published' && $job->get('publishedAt') === null) {
            $job->set('publishedAt', self::now());
        }
        $job->set('updatedAt', self::now());
        $this->data->saveType($job, rules: (new Job())->rules());
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
    private function owned(ServerRequestInterface $request): DynamicRecord
    {
        $user = $this->user($request);
        $job = $this->data->findIn($this->job(), (string) $request->getAttribute('id'));
        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }
        if ($job->get('company') !== $user->company) {
            throw new ForbiddenHttpException('This job belongs to another company.');
        }

        return $job;
    }

    /**
     * A field the server sets (not in WRITABLE), and the id of a new job, is refused rather than
     * dropped: a client that sends one learns it had no effect. A key that is no field at all
     * the write class refuses.
     *
     * @param array<string, mixed> $body
     * @throws ValidationException
     */
    private function refuseServerKeys(array $body, bool $new): void
    {
        $job = $this->job();
        $errors = [];
        foreach (array_keys($body) as $key) {
            $key = (string) $key;
            $serverField = isset($job->fields[$key]) && !in_array($key, self::WRITABLE, true);
            $newId = $new && in_array($key, ['_id', $job->idField], true);
            if ($serverField || $newId) {
                $errors[$key] = ['The server sets "' . $key . '".'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException(new ValidationResult($errors));
        }
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private static function now(): string
    {
        return Contexts::clock()->now()->format('Y-m-d H:i:s');
    }
}
