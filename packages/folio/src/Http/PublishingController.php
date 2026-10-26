<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\PreviewLinks;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Publishing actions besides the form save: autosave, unpublish, discard, restore, share links. */
final class PublishingController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly Publisher $publisher,
        private readonly string $prefix,
        private readonly DataManager $dm,
        private readonly DraftRecordFactory $drafts,
        private readonly TemplateEngineInterface $engine,
        private readonly ?PreviewLinks $links = null,
        private readonly ?FrontendResolver $resolver = null,
        private readonly ?\Preflow\Auth\Access\Access $access = null,
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
            return new Response(404, [], \Preflow\Folio\FolioText::get('folio.errors.not_found'));
        }

        $typeDef = $current->getType();
        $body = (array) $request->getParsedBody();
        $locale = is_string($body['_locale'] ?? null) ? $body['_locale'] : $typeDef->locales->default;
        $record = $this->drafts->build($typeDef, $request, $current->toArray(), $locale, writeUploads: false);
        $loaded = is_string($body['_version'] ?? null) && ctype_digit($body['_version']) ? (int) $body['_version'] : null;
        if (!$this->mayPublish($type)) {
            $record->set('_publish_at', ''); // an unreviewed change cancels a pending schedule
        }

        try {
            $this->publisher->saveDraft($record, $loaded);
        } catch (VersionConflict) {
            return $this->status(\Preflow\Folio\FolioText::get('folio.publishing.not_saved_conflict'), null, true);
        }

        return $this->status(self::stateText($this->publisher->state($record)), (int) $record->get('_version'));
    }

    /** Copy a revision into the working copy (publish again to make it live). */
    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        $revision = (string) $request->getAttribute('rev', '');
        if ($type === null || ($restored = $this->publisher->restore($type, $id, $revision)) === null) {
            return new Response(404, [], \Preflow\Folio\FolioText::get('folio.errors.not_found'));
        }
        if (!$this->mayPublish($type) && (string) $restored->get('_publish_at') !== '') {
            $restored->set('_publish_at', ''); // restored content goes live only when someone who may publish says so
            $this->dm->saveType($restored, false);
        }

        return $this->backToEditor($type, $id, '?restored=1');
    }

    /** A signed link to the working copy on its real URL, for people who are not logged in. */
    public function share(ServerRequestInterface $request): ResponseInterface
    {
        $working = $this->shareable($request);
        if ($working === null) {
            return new Response(404, [], \Preflow\Folio\FolioText::get('folio.errors.not_found'));
        }

        $nonce = $working->get('_share_token');
        if (!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            $nonce = $this->rotateShareToken($working);
        }

        $typeDef = $working->getType();
        $locale = ((array) $request->getParsedBody())['_locale'] ?? null;
        $locale = is_string($locale) && $typeDef->locales->has($locale) ? $locale : $typeDef->locales->default;
        $path = $this->resolver?->urlFor($working, $locale);
        $url = $path !== null
            ? self::origin($request) . $path . '?folio_preview=' . $this->links?->create($typeDef->key, (string) $working->getId(), $nonce)
            : null;

        return $this->shareFragment($working, $url, $url === null ? \Preflow\Folio\FolioText::get('folio.publishing.no_url') : null);
    }

    /** Rotate the record's share token: every earlier preview link stops working. */
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $working = $this->shareable($request);
        if ($working === null) {
            return new Response(404, [], \Preflow\Folio\FolioText::get('folio.errors.not_found'));
        }
        $this->rotateShareToken($working);

        return $this->shareFragment($working, null, \Preflow\Folio\FolioText::get('folio.publishing.revoked'));
    }

    private function mayPublish(string $type): bool
    {
        return $this->access?->can($type . '.publish') ?? true;
    }

    public static function stateText(PublishState $state): string
    {
        return \Preflow\Folio\FolioText::get($state === PublishState::Published ? 'folio.publishing.published' : 'folio.publishing.saved_not_published');
    }

    public function unpublish(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        if ($type === null) {
            return new Response(404, [], \Preflow\Folio\FolioText::get('folio.errors.unknown_type'));
        }
        $this->publisher->unpublish($type, $id);

        return $this->backToEditor($type, $id);
    }

    public function discard(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        if ($type === null) {
            return new Response(404, [], \Preflow\Folio\FolioText::get('folio.errors.unknown_type'));
        }
        $this->publisher->discard($type, $id);

        return $this->backToEditor($type, $id);
    }

    private function shareable(ServerRequestInterface $request): ?\Preflow\Data\DynamicRecord
    {
        [$type, $id] = $this->target($request);
        if ($type === null || $this->links === null || !$this->links->enabled() || $this->resolver?->routeFor($type) === null) {
            return null;
        }

        return $this->dm->findType($type, $id);
    }

    /** The share token is not content: changing it does not bump _version. */
    private function rotateShareToken(\Preflow\Data\DynamicRecord $working): string
    {
        $nonce = bin2hex(random_bytes(16));
        $working->set('_share_token', $nonce);
        $this->dm->saveType($working, false);

        return $nonce;
    }

    private function shareFragment(\Preflow\Data\DynamicRecord $working, ?string $url, ?string $message): ResponseInterface
    {
        $base = $this->prefix . '/' . $working->getType()->key . '/' . rawurlencode((string) $working->getId());

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/publishing/share.twig', [
            'url' => $url,
            'message' => $message,
            'days' => intdiv($this->links?->ttl() ?? 0, 86400),
            'revoke_url' => $base . '/share/revoke',
        ]));
    }

    private static function origin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        return $uri->getHost() !== ''
            ? $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '')
            : '';
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
