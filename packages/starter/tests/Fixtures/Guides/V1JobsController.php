<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Json;
use Preflow\Data\DataManager;
use Preflow\Data\Describe\Description;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\TypeRegistry;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A versioned API with links between records, as the preflow.dev guide shows it (the guide
 * quotes this file; tests/Api/GuideSamplesTest runs it). GET /v1/jobs/{id} answers a job as the
 * core read class gives it, plus "_links": where the job itself lives, and where each record it
 * points to lives:
 *
 *     {"_id": "j1", "title": "Gardener", "company": "c1", …,
 *      "_links": {"self": {"href": "/v1/jobs/j1"}, "company": {"href": "/v1/companies/c1"}}}
 *
 * The links come from the description: a relation field names its "target" type, and PATHS says
 * where that type lives in this version of the API. Nothing is guessed from field names.
 */
#[Route('/v1/jobs')]
final class V1JobsController
{
    /** Where each type lives in /v1. A relation to a type not listed here gets no link. */
    private const array PATHS = [
        'job' => '/v1/jobs',
        'company' => '/v1/companies',
    ];

    public function __construct(
        private readonly DataManager $data,
        private readonly TypeRegistry $types,
        private readonly Description $description,
        private readonly RecordOutput $output,
    ) {}

    #[Get('/{id}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $record = $this->data->findIn($this->types->get('job'), (string) $request->getAttribute('id'));
        // A draft answers like a job that does not exist.
        if ($record === null || $record->get('status') !== 'published') {
            throw new NotFoundHttpException('Job not found.');
        }

        $job = $this->output->toArray($record);
        $job['_links'] = $this->links('job', $job);

        return Json::data($job);
    }

    /**
     * "self", then one link per relation field that holds a value: one link for a single
     * relation ("value": "id"), a list of links for a multiple one ("value": "id_list").
     *
     * @param array<string, mixed> $record the read output
     * @return array<string, mixed>
     */
    private function links(string $type, array $record): array
    {
        $links = ['self' => ['href' => self::PATHS[$type] . '/' . $record['_id']]];
        $fields = $this->description->toArray()['types'][$type]['fields'];
        foreach ($fields as $name => $field) {
            $path = self::PATHS[$field['target'] ?? ''] ?? null;
            if ($path === null || $record[$name] === null || $record[$name] === []) {
                continue;
            }
            $links[$name] = is_array($record[$name])
                ? array_map(static fn (string $id): array => ['href' => $path . '/' . $id], $record[$name])
                : ['href' => $path . '/' . $record[$name]];
        }

        return $links;
    }
}
