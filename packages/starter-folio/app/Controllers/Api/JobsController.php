<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Jobs\JobFilters;
use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use App\Models\Job;
use App\Models\User;
use Preflow\Auth\Access\Access;
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
use Preflow\Publishing\PublishFailed;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Rbac\TypePermissions;
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
 * names) and written with the core write class (RecordInput), then saved through publishing's
 * RecordWriter, as Folio saves it: a save changes the working copy, and only a publish puts it
 * on the site.
 *
 * What this controller adds to the core: who may write (the job's company, job.publish to
 * publish), what the server sets (company, slug, dates), the publish switch ("status") and, in
 * the answer, when the published copy went live ("_published_at"), the publish state ("_status",
 * for the owner) and the job's page ("_url").
 */
#[Route('/api/jobs')]
final class JobsController
{
    /** The fields a client writes. The server sets the others; a body that names one is refused. */
    private const array WRITABLE = ['title', 'place', 'employmentType', 'remote', 'salaryFrom', 'salaryTo', 'description'];

    public function __construct(
        private readonly JobSearch $search,
        private readonly JobResource $resource,
        private readonly DataManager $data,
        private readonly TypeRegistry $types,
        private readonly RecordOutput $output,
        private readonly RecordInput $input,
        private readonly RecordWriter $writer,
        private readonly Publisher $publisher,
        private readonly RecordDeleter $deleter,
        private readonly Access $access,
    ) {}

    #[Get('/')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        // The same filters, and the same normalising of odd values, as the /jobs page; published copies only.
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
        // Only a published copy: a draft answers like a job that does not exist.
        $job = $this->publisher->live('job', (string) $request->getAttribute('id'));
        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        return Json::data($this->present($job));
    }

    #[Post('/')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        [$body, $status] = $this->statusOf($this->body($request));
        $this->refuseServerKeys($body, new: true);

        // The body onto a new job (refused: 422), then what the server sets.
        $job = $this->input->create($this->job(), $body);
        $job->set('company', $user->company);
        // Made from the title once; editing the title later keeps the address people may have shared.
        $job->set('slug', Slugs::unique($this->data, Job::class, (string) $job->get('title')));
        $job->set('createdAt', self::now());
        $this->save($job, $user, $status, new: true);

        return Json::data($this->owner($job), status: 201, headers: ['Location' => '/api/jobs/' . $job->getId()]);
    }

    #[Patch('/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        $job = $this->owned($request);
        [$body, $status] = $this->statusOf($this->body($request));
        $this->refuseServerKeys($body, new: false);

        // Only what the body names changes, in the working copy.
        $this->input->update($job, $body);
        $this->save($job, $user, $status, new: false);

        return Json::data($this->owner($job));
    }

    #[Delete('/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        // Also the published copy and the revisions: without them the job would stay on the site.
        $this->deleter->delete('job', [(string) $this->owned($request)->getId()]);

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

    /**
     * A published job: the core read output, when it went live ("_published_at", the published
     * copy's own column: the order among jobs posted on the same day) and its page on the site.
     *
     * @return array<string, mixed>
     */
    private function present(DynamicRecord $job): array
    {
        return $this->output->toArray($job) + [
            '_published_at' => $job->get('_published_at'),
            '_url' => $this->resource->url($job->get('slug')),
        ];
    }

    /** @return array<string, mixed> the owner's view: the working copy, with its publish state (draft, published, changed) */
    private function owner(DynamicRecord $job): array
    {
        return $this->output->toArray($job) + [
            '_status' => $this->publisher->state($job)->value,
            '_url' => $this->resource->url($job->get('slug')),
        ];
    }

    /**
     * Saves the working copy through the RecordWriter, and publishes or unpublishes it when the
     * body asked. A draft skips the rules in Folio (staff save half-finished work), so they run
     * here first, with the model's own (the salary range), as in the employer area's form: the
     * client hears of every problem before anything is stored. The first publish dates the job
     * ("postedOn"); later ones keep the date, so the job keeps its place in the list.
     *
     * @param 'draft'|'published'|null $status
     * @throws ValidationException nothing saved (422)
     */
    private function save(DynamicRecord $job, User $user, ?string $status, bool $new): void
    {
        if ($status === 'published' && $job->get('postedOn') === null) {
            $job->set('postedOn', Contexts::clock()->now()->format('Y-m-d'));
        }
        $job->set('updatedAt', self::now());
        $this->data->validate($job, (new Job())->rules());

        // The revision names the employer, so staff see in Folio who published it. A save by an
        // account that may not publish cancels a publish staff scheduled, as a save in Folio does.
        $options = new SaveOptions(
            by: 'employer:' . $user->email,
            mayPublish: TypePermissions::allows($this->access, $this->job(), 'publish'),
            publish: $status === 'published',
        );
        try {
            if ($new) {
                $this->writer->create($job, $options);
            } else {
                $this->writer->save($job, $options);
            }
        } catch (PublishFailed $e) {
            throw $e->cause(); // the draft is stored; the client shows what to fix
        }
        if ($status === 'draft') {
            // Off the site; the working copy stays.
            $this->publisher->unpublish('job', (string) $job->getId());
        }
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

    /** The working copy, if it is the caller's company's: 404 when it does not exist, 403 when it is someone else's. */
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
     * "status" is no field of a job: publishing decides what the public sees. The body's
     * "status" (draft or published) asks to unpublish or publish; it is taken out of the body
     * before the write class reads the rest. Publishing needs job.publish (config/roles.php gives
     * it to the employer role), checked before anything is saved: a token acts as its user, so an
     * account that may only post drafts cannot publish through the API either.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: 'draft'|'published'|null} the body without "status", and the status
     */
    private function statusOf(array $body): array
    {
        if (!array_key_exists('status', $body)) {
            return [$body, null];
        }
        $status = $body['status'];
        unset($body['status']);
        if ($status !== 'draft' && $status !== 'published') {
            throw new ValidationException(new ValidationResult(['status' => ['The status must be draft or published.']]));
        }
        if ($status === 'published' && !TypePermissions::allows($this->access, $this->job(), 'publish')) {
            throw new ForbiddenHttpException('Your account may post drafts; publishing needs a full employer account.');
        }

        return [$body, $status];
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
