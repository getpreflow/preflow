<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Publishing actions besides the form save: autosave, unpublish, discard, restore. */
final class PublishingController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly Publisher $publisher,
        private readonly string $prefix,
        private readonly DataManager $dm,
        private readonly DraftRecordFactory $drafts,
        private readonly TemplateEngineInterface $engine,
    ) {}

    /**
     * Save the working copy from the form (no rules, no uploads) and answer
     * with the header's save state plus the new _version (out-of-band), so
     * the editor's next save is not taken for a conflict.
     */
    public function autosave(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        $current = $type !== null ? $this->dm->findType($type, $id) : null;
        if ($type === null || $current === null) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $current->getType();
        $body = (array) $request->getParsedBody();
        $locale = is_string($body['_locale'] ?? null) ? $body['_locale'] : $typeDef->locales->default;
        $record = $this->drafts->build($typeDef, $request, $current->toArray(), $locale, writeUploads: false);
        $loaded = is_string($body['_version'] ?? null) && ctype_digit($body['_version']) ? (int) $body['_version'] : null;

        try {
            $this->publisher->saveDraft($record, $loaded);
        } catch (VersionConflict) {
            return $this->status('Not saved — someone else changed this record. Reload or save to overwrite.', null, true);
        }

        return $this->status(self::stateText($this->publisher->state($record)), (int) $record->get('_version'));
    }

    /** Copy a revision into the working copy (publish again to make it live). */
    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        $revision = (string) $request->getAttribute('rev', '');
        if ($type === null || $this->publisher->restore($type, $id, $revision) === null) {
            return new Response(404, [], 'Not found');
        }

        return $this->backToEditor($type, $id, '?restored=1');
    }

    public static function stateText(PublishState $state): string
    {
        return $state === PublishState::Published ? 'Published' : 'Saved · not published';
    }

    public function unpublish(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        if ($type === null) {
            return new Response(404, [], 'Unknown type');
        }
        $this->publisher->unpublish($type, $id);

        return $this->backToEditor($type, $id);
    }

    public function discard(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        if ($type === null) {
            return new Response(404, [], 'Unknown type');
        }
        $this->publisher->discard($type, $id);

        return $this->backToEditor($type, $id);
    }

    private function status(string $text, ?int $version, bool $conflict = false): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/publishing/status.twig', [
            'text' => $text,
            'version' => $version,
            'conflict' => $conflict,
        ]));
    }

    /** @return array{0: string|null, 1: string} the type (null unless it publishes) and id */
    private function target(ServerRequestInterface $request): array
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');

        return [$this->catalog->has($type) && $this->publisher->enabled($type) ? $type : null, $id];
    }

    private function backToEditor(string $type, string $id, string $query = ''): ResponseInterface
    {
        $url = $this->catalog->get($type)?->singleton === true
            ? $this->prefix . '/' . $type
            : $this->prefix . '/' . $type . '/' . rawurlencode($id) . '/edit';

        return new Response(302, ['Location' => $url . $query]);
    }
}
