<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Jobs\JobWriter;
use App\Middleware\EmployerAuth;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use Nyholm\Psr7\Response;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\SortDirection;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Route;
use Preflow\Validation\ValidationException;
use Preflow\View\PageResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The signed-in employer's jobs: list, post, edit, publish. Every job is looked up through its owner. */
#[Route('/employer')]
#[Middleware(EmployerAuth::class)]
final class EmployerJobController
{
    public function __construct(
        private readonly JobWriter $writer,
        private readonly DataManager $data,
        private readonly PageResponder $pages,
        private readonly Access $access,
    ) {}

    #[Get('/')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        // The working copies: the employer sees drafts and edits that are not published yet.
        $jobs = $user->company === null ? [] : array_values($this->data->query(Job::class)
            ->where('company', $user->company)
            ->orderBy('updatedAt', SortDirection::Desc)
            ->get()->items());

        return $this->pages->render('_views/employer/index.twig', [
            'company' => $user->company === null ? null : $this->data->find(Company::class, $user->company),
            'jobs' => $jobs,
            'states' => $this->writer->states($jobs),
        ]);
    }

    #[Get('/jobs/new')]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->form(new Job(), '/employer/jobs');
    }

    #[Post('/jobs')]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);
        try {
            $this->writer->create($input, $this->user($request));
        } catch (ValidationException $e) {
            return $this->form(new Job(), '/employer/jobs', $input, $e);
        }

        return $this->back($request, 'Draft saved.');
    }

    #[Get('/jobs/{id}/edit')]
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->owned($request);

        return $this->form($job, '/employer/jobs/' . $job->uuid);
    }

    #[Post('/jobs/{id}')]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->owned($request);
        $input = $this->input($request);
        try {
            $this->writer->update($job, $input, $this->access->can('job.publish'));
        } catch (ValidationException $e) {
            return $this->form($job, '/employer/jobs/' . $job->uuid, $input, $e);
        }

        return $this->back($request, 'Changes saved.');
    }

    #[Post('/jobs/{id}/publish')]
    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->owned($request);
        // config/roles.php gives job.publish to the employer role; an account without it posts drafts.
        // Back to the list with the reason: a production 403 page would only say "Forbidden".
        if (!$this->access->can('job.publish')) {
            return $this->back($request, 'Your account may post drafts; publishing needs a full employer account.', 'error');
        }
        try {
            $this->writer->publish($job, $this->user($request));
        } catch (ValidationException $e) {
            // Staff can save a half-finished draft in Folio; publishing checks the rules, so the
            // employer gets the form with what to fix.
            return $this->form($job, '/employer/jobs/' . $job->uuid, [], $e);
        }

        return $this->back($request, 'Published.');
    }

    #[Post('/jobs/{id}/unpublish')]
    public function unpublish(ServerRequestInterface $request): ResponseInterface
    {
        $this->writer->unpublish($this->owned($request));

        return $this->back($request, 'Unpublished. It is a draft again.');
    }

    private function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(Authenticatable::class);
        if (!$user instanceof User) {
            throw new NotFoundHttpException('Page not found');
        }

        return $user;
    }

    /** Another company's job gives the same 404 as one that does not exist. */
    private function owned(ServerRequestInterface $request): Job
    {
        $job = $this->writer->ownedBy((string) $request->getAttribute('id'), $this->user($request));
        if ($job === null) {
            throw new NotFoundHttpException('Page not found');
        }

        return $job;
    }

    /** @return array<string, string> the posted fields as strings */
    private function input(ServerRequestInterface $request): array
    {
        return array_filter((array) $request->getParsedBody(), is_string(...));
    }

    /** @param array<string, string> $input */
    private function form(Job $job, string $action, array $input = [], ?ValidationException $error = null): ResponseInterface
    {
        return $this->pages->render('_views/employer/form.twig', [
            'job' => $job,
            'action' => $action,
            'old' => $input,
            'errors' => $error?->errorBag(),
        ], $error === null ? 200 : 422);
    }

    /** @param 'success'|'error' $kind */
    private function back(ServerRequestInterface $request, string $message, string $kind = 'success'): ResponseInterface
    {
        $request->getAttribute(SessionInterface::class)?->flash($kind, $message);

        return new Response(302, ['Location' => '/employer']);
    }
}
