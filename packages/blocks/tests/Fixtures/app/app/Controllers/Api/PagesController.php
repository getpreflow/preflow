<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Json;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Patch;
use Preflow\Routing\Attributes\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pages as JSON, written the way a site writes its API: its own fields by name, the layout and
 * areas through the core (LayoutResource to read, LayoutInput to merge a body), and the save through
 * DataManager::saveType(), so a refusal is the core's (422 with "errors", as Folio's form refuses).
 * An areas field of the page (0.92) is read with LayoutResource::areasField() and merged with
 * LayoutInput::mergeField().
 * A real site adds its authentication (TokenAuthMiddleware) to the write.
 */
#[Route('/api/pages')]
final class PagesController
{
    /** The page's own fields this API reads and writes. */
    private const array FIELDS = ['title', 'slug'];

    public function __construct(
        private readonly DataManager $data,
        private readonly LayoutResource $layouts,
        private readonly LayoutInput $input,
    ) {}

    #[Get('/{id}')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return Json::data($this->present($this->page($request)));
    }

    #[Patch('/{id}')]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->page($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $values = $page->toArray();
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $values[$field] = $body[$field];
            }
        }
        // "layout" and "areas" of the body, merged under the core's rules (refusals are 422).
        $values = $this->input->merge($page->getType(), $values, $body);
        // Its areas fields (a layout from a named set and its areas), by the same rules.
        foreach ($this->areasFields($page) as $name => $field) {
            if (array_key_exists($name, $body)) {
                $values[$name] = $this->input->mergeField($field, $values[$name] ?? null, $body[$name]);
            }
        }
        $this->data->saveType(DynamicRecord::fromArray($page->getType(), $values));

        return Json::data($this->present($this->data->findIn($page->getType(), (string) $page->getId()) ?? $page));
    }

    private function page(ServerRequestInterface $request): DynamicRecord
    {
        return $this->data->findType('page', (string) $request->getAttribute('id'))
            ?? throw new NotFoundHttpException('Page not found.');
    }

    /** @return array<string, mixed> */
    private function present(DynamicRecord $page): array
    {
        $data = ['id' => $page->getId()];
        foreach (self::FIELDS as $field) {
            $data[$field] = $page->get($field);
        }

        foreach ($this->areasFields($page) as $name => $field) {
            $data[$name] = $this->layouts->areasField($field, $page->get($name));
        }

        return $data + ($this->layouts->toArray($page) ?? []);
    }

    /** @return array<string, \Preflow\Data\TypeFieldDefinition> the page's fields of the type "areas" */
    private function areasFields(DynamicRecord $page): array
    {
        return array_filter($page->getType()->fields, static fn ($field): bool => $field->type === 'areas');
    }
}
