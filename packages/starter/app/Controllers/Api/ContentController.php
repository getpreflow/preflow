<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\User;
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
 *     GET   /api/content/{type}          a page of records (RecordOutput)       ?page=2 ?locale=de
 *     GET   /api/content/{type}/{id}     one record (RecordOutput)              ?locale=de
 *     POST  /api/content/{type}          a new record (RecordInput, saveType()) token
 *     PATCH /api/content/{type}/{id}     a change (RecordInput, saveType())     token, ?locale=de
 *
 * A record reads as {"_id": …, <field>: …}; a translatable field as {"en": …, "de": …}, or one
 * language with ?locale=. A write sends the same shape, and only what changes: {"summary":
 * {"de": …}} changes the German text and keeps the English. Errors: 404 for a type this API
 * does not serve, 401 without a token, 403 when the type or mayWrite() refuses, 422 with the
 * core's keys ("name", "summary.de", "_id") when the body or the type's rules do.
 *
 * Nothing is served by convention: a type is readable and writable here only once it is in
 * TYPES, and writable only for whom mayWrite() says.
 */
#[Route('/api/content')]
final class ContentController
{
    /** The types this API serves, by key. Add one on purpose, with its line in mayWrite(). */
    private const array TYPES = ['company'];

    /** Records per page of a list. */
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly DataManager $data,
        private readonly TypeRegistry $types,
        private readonly Description $description,
        private readonly RecordOutput $output,
        private readonly RecordInput $input,
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
        $page = $this->data->queryIn($type)->orderBy($type->idField)->paginate(self::PER_PAGE, $this->page($request));
        $options = $this->readOptions($request);

        return Json::data(
            array_map(fn (DynamicRecord $record): array => $this->output->toArray($record, $options), $page->items),
            ['page' => $page->currentPage, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage],
        );
    }

    #[Get('/{type}/{id}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $record = $this->found($this->served($request), $request);

        return Json::data($this->output->toArray($record, $this->readOptions($request)));
    }

    #[Post('/{type}')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->served($request);
        if (!$this->mayWrite($this->user($request), $type, null)) {
            throw new ForbiddenHttpException('You may not create this.');
        }

        $record = $this->refusedAs403(fn (): DynamicRecord => $this->input->create($type, $this->body($request), $this->locale($request)));
        // An id the client gives must be new: saving it would otherwise overwrite that record.
        $id = $record->getId();
        if ($id === null) {
            $record->set($type->idField, bin2hex(random_bytes(16)));
        } elseif ($this->data->findIn($type, $id) !== null) {
            throw new ValidationException(new ValidationResult(['_id' => ['A record with this id exists already.']]));
        }
        $this->data->saveType($record);

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
        $record = $this->found($type, $request);
        if (!$this->mayWrite($this->user($request), $type, $record)) {
            throw new ForbiddenHttpException('You may not change this.');
        }

        // Only what the body names changes; the rules run on the whole record as it is saved.
        $this->refusedAs403(fn (): DynamicRecord => $this->input->update($record, $this->body($request), $this->locale($request)));
        $this->data->saveType($record);

        return Json::data($this->output->toArray($record, $this->readOptions($request)));
    }

    /**
     * Who may write a record of a served type ($record null: create one). The starter has no
     * roles, so this says it per type: an employer changes their own company and creates none
     * (`php preflow employer:create` does). A type added to TYPES needs its line here, or nobody
     * may write it. (With preflow/rbac, ask TypePermissions::allows() instead.)
     */
    private function mayWrite(User $user, TypeDefinition $type, ?DynamicRecord $record): bool
    {
        return match ($type->key) {
            'company' => $record !== null && $record->getId() === $user->company,
            default => false,
        };
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

    private function found(TypeDefinition $type, ServerRequestInterface $request): DynamicRecord
    {
        return $this->data->findIn($type, (string) $request->getAttribute('id'))
            ?? throw new NotFoundHttpException('No such record.');
    }

    /**
     * A write the type refuses ("readonly": true, "creatable": false) is a 403; what the body
     * gets wrong stays a 422 (ValidationException). The type is asked by the write class itself:
     * RecordInput::create() and update() call TypeDefinition::assertAllows() first.
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

    private function readOptions(ServerRequestInterface $request): ReadOptions
    {
        return new ReadOptions(locale: $this->locale($request));
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
