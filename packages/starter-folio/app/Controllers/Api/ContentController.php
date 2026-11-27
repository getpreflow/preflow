<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\User;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Http\TokenAuthMiddleware;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Json;
use Preflow\Data\DataManager;
use Preflow\Data\Describe\Description;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Records\ReadOptions;
use Preflow\Data\Records\RecordInput;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\TypeActionRefused;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordChecks;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Rbac\TypePermissions;
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
 * One API for the types listed in TYPES, built on the core's three classes:
 *
 *     GET   /api/content                 what this API serves (Description)
 *     GET   /api/content/{type}          a page of published records (RecordOutput)   ?page=2 ?locale=de ?with=media
 *     GET   /api/content/{type}/{id}     one published record (RecordOutput)          ?locale=de ?with=media
 *     POST  /api/content/{type}          a new record (RecordInput, RecordWriter)     token, {type}.create
 *     PATCH /api/content/{type}/{id}     a change (RecordInput, RecordWriter)         token, {type}.edit, ?locale=de
 *     POST  /api/content/{type}/{id}/publish   the working copy, live (Publisher)     token, {type}.publish
 *
 * A record reads as {"_id": …, <field>: …}; a translatable field as {"en": …, "de": …}, or one
 * language with ?locale=; ?with=media adds "_media", the URLs and sizes of the media it points
 * to. A write sends the same shape, and only what changes: {"summary": {"de": …}} changes the
 * German text and keeps the English.
 *
 * Reads show what the site shows: the published copy of a type that publishes. A write changes
 * only the working copy, as Folio's Save does, and answers with it; it never publishes, so a
 * draft edit someone saved before (staff in Folio) never goes live as a side effect. Publishing
 * is its own request, as Folio's Publish button is: POST …/publish puts the whole working copy
 * live. The roles decide for the whole type, as in Folio (config/roles.php, Folio's Settings →
 * Roles).
 *
 * Errors: 404 for a type this API does not serve, 401 without a token, 403 when the type or the
 * user's roles refuse, 422 with the core's keys ("name", "summary.de", "_id") when the body or
 * the type's rules do.
 *
 * Nothing is served by convention: a type is readable and writable here only once it is in TYPES.
 */
#[Route('/api/content')]
final class ContentController
{
    /** The types this API serves, by key. Add one on purpose. */
    private const array TYPES = ['company'];

    /** Records per page of a list. */
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly DataManager $data,
        private readonly TypeRegistry $types,
        private readonly Description $description,
        private readonly RecordOutput $output,
        private readonly RecordInput $input,
        private readonly RecordWriter $writer,
        private readonly RecordChecks $checks,
        private readonly Publisher $publisher,
        private readonly Access $access,
    ) {}

    #[Get('/')]
    public function describe(): ResponseInterface
    {
        // The core's description of every type, cut down to the ones served here.
        $description = $this->description->toArray();
        $description['types'] = array_intersect_key((array) $description['types'], array_flip(self::TYPES));

        return Json::data($description);
    }

    #[Get('/{type}')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->served($request);
        // Publisher::query(): the published copies of a type that publishes, else its records.
        $page = $this->publisher->query($type->key)->orderBy($type->idField)->paginate(self::PER_PAGE, $this->page($request));
        $options = $this->readOptions($request);

        return Json::data(
            array_map(fn (DynamicRecord $record): array => $this->output->toArray($record, $options), $page->items),
            ['page' => $page->currentPage, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage],
        );
    }

    #[Get('/{type}/{id}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->served($request);
        // Publisher::live(): what the site shows; a draft answers like a record that does not exist.
        $record = $this->publisher->live($type->key, (string) $request->getAttribute('id'))
            ?? throw new NotFoundHttpException('No such record.');

        return Json::data($this->output->toArray($record, $this->readOptions($request)));
    }

    #[Post('/{type}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->served($request);
        $user = $this->user($request);
        $this->mayDo($type, 'create');

        $record = $this->refusedAs403(fn (): DynamicRecord => $this->input->create($type, $this->body($request), $this->locale($request)));
        // An id the client gives must be new (RecordWriter::create() gives one when there is none).
        $id = $record->getId();
        if ($id !== null && $this->data->findIn($type, $id) !== null) {
            throw new ValidationException(new ValidationResult(['_id' => ['A record with this id exists already.']]));
        }
        $this->checked($record);
        $this->writer->create($record, $this->saveOptions($type, $user));

        return Json::data(
            $this->output->toArray($record, $this->readOptions($request)),
            status: 201,
            headers: ['Location' => '/api/content/' . $type->key . '/' . $record->getId()],
        );
    }

    #[Patch('/{type}/{id}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->served($request);
        $user = $this->user($request);
        $this->mayDo($type, 'edit');
        $record = $this->workingCopy($type, $request);

        // Only what the body names changes; the rules run on the whole record before it is saved.
        $this->refusedAs403(fn (): DynamicRecord => $this->input->update($record, $this->body($request), $this->locale($request)));
        $this->checked($record);
        $this->writer->save($record, $this->saveOptions($type, $user));

        // The answer is the working copy, which the public sees once it is published.
        return Json::data($this->output->toArray($record, $this->readOptions($request)));
    }

    #[Post('/{type}/{id}/publish')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->served($request);
        $user = $this->user($request);
        $this->mayDo($type, 'publish');
        if (!$this->publisher->enabled($type->key)) {
            throw new NotFoundHttpException('This type does not publish: a save puts a change live.');
        }
        $record = $this->workingCopy($type, $request);

        // The working copy as it is, every edit in it, put live; the publish runs the type's rules (422).
        $live = $this->publisher->publish($record, 'api:' . $user->email);

        return Json::data($this->output->toArray($live, $this->readOptions($request)));
    }

    /** The working copy: the record editors change, published or not. */
    private function workingCopy(TypeDefinition $type, ServerRequestInterface $request): DynamicRecord
    {
        return $this->data->findIn($type, (string) $request->getAttribute('id'))
            ?? throw new NotFoundHttpException('No such record.');
    }

    /** 403 unless the token's user may $verb records of the type: their role's "{type}.{verb}", and the type's own answer. */
    private function mayDo(TypeDefinition $type, string $verb): void
    {
        if (!TypePermissions::allows($this->access, $type, $verb)) {
            throw new ForbiddenHttpException('Your account may not ' . $verb . ' this.');
        }
    }

    /**
     * The rules a publish runs (the bound RecordChecks: the core's, and Folio's), before anything
     * is stored: a draft skips them in Folio (staff save half-finished work), but an API client
     * hears of every problem at once.
     *
     * @throws ValidationException
     */
    private function checked(DynamicRecord $record): void
    {
        $this->checks->validate($record);
    }

    /**
     * A save of the working copy, never a publish. The revision names the user, as Folio shows it;
     * a save by someone who may not publish cancels a publish staff scheduled, as a save in Folio does.
     */
    private function saveOptions(TypeDefinition $type, User $user): SaveOptions
    {
        return new SaveOptions(by: 'api:' . $user->email, mayPublish: TypePermissions::allows($this->access, $type, 'publish'));
    }

    /** The type the path names, if this API serves it: 404 for any other, a type of the site or not. */
    private function served(ServerRequestInterface $request): TypeDefinition
    {
        $key = (string) $request->getAttribute('type');
        if (!in_array($key, self::TYPES, true)) {
            throw new NotFoundHttpException('No such type.');
        }

        return $this->types->get($key);
    }

    /**
     * A write the type refuses ("readonly": true, "creatable": false) is a 403; what the body
     * gets wrong stays a 422 (ValidationException).
     *
     * @param \Closure(): DynamicRecord $write
     */
    private function refusedAs403(\Closure $write): DynamicRecord
    {
        try {
            return $write();
        } catch (TypeActionRefused $e) {
            throw new ForbiddenHttpException($e->getMessage());
        }
    }

    private function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(Authenticatable::class);
        if (!$user instanceof User) {
            throw new ForbiddenHttpException('This account may not write.');
        }

        return $user;
    }

    /** ?locale=de: the language a read picks, and the one a write's single values are in. */
    private function locale(ServerRequestInterface $request): ?string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /** ?locale=de and ?with=media (the extras the packages add on request). */
    private function readOptions(ServerRequestInterface $request): ReadOptions
    {
        $with = $request->getQueryParams()['with'] ?? '';

        return new ReadOptions(locale: $this->locale($request), with: is_string($with) && $with !== '' ? explode(',', $with) : []);
    }

    private function page(ServerRequestInterface $request): int
    {
        $page = $request->getQueryParams()['page'] ?? '1';

        return is_string($page) && ctype_digit($page) && (int) $page > 0 ? (int) $page : 1;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
