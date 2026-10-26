<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\Locales;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\FolioText;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Server-rendered card fragments for the block picker (fetched by htmx). */
final class BlockEditorController
{
    /** A field name: "sections" or nested "sections[<uid>][<field>]…". */
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\[[a-f0-9]{16}\]\[[A-Za-z_][A-Za-z0-9_]*\])*$/';

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly Locales $locales,
        private readonly ?\Preflow\Data\DataManager $dm = null,
        private readonly ?\Preflow\Folio\Content\TypeCatalog $catalog = null,
    ) {}

    /**
     * A duplicate of one card (POST, the card's inputs + CSRF): the posted values
     * merged with the stored block, re-rendered with fresh uids.
     */
    public function duplicate(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';
        $uid = is_string($query['uid'] ?? null) ? $query['uid'] : '';
        if (preg_match(self::NAME, $name) !== 1 || preg_match('/^[a-f0-9]{16}$/', $uid) !== 1) {
            return new Response(400, [], FolioText::get('folio.errors.invalid_block'));
        }
        $locale = is_string($query['locale'] ?? null) && $this->locales->has($query['locale']) ? $query['locale'] : $this->locales->default;
        $depth = max(0, (int) ($query['depth'] ?? 0));
        $keys = self::keys($name);

        // The block's current values, posted under their full names.
        $posted = (array) $request->getParsedBody();
        foreach ($keys as $key) {
            $posted = is_array($posted[$key] ?? null) ? $posted[$key] : [];
        }

        // The stored block at that path (other locales, uploads), if the record exists.
        $blocks = $this->fieldTypes->get('blocks');
        $type = is_string($query['type'] ?? null) ? $query['type'] : '';
        $id = is_string($query['id'] ?? null) ? $query['id'] : '';
        $stored = null;
        $record = null;
        if ($type !== '' && $this->catalog?->has($type) && $this->dm !== null) {
            $record = ['type' => $type, 'id' => $id !== '' ? $id : null];
            $existing = $id !== '' ? $this->dm->findType($type, $id) : null;
            if ($existing !== null && $blocks instanceof BlocksFieldType) {
                $stored = $blocks->storedLevel($existing->toArray(), $keys);
            }
        }

        $html = $blocks instanceof BlocksFieldType
            ? $blocks->renderDuplicate($name, $uid, $posted[$uid] ?? null, $stored, $this->locales, $locale, $depth, $record)
            : null;

        return $html === null
            ? new Response(404, [], FolioText::get('folio.errors.nothing_to_duplicate'))
            : new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /** @return list<string> "a[b][c]" -> ["a", "b", "c"] */
    private static function keys(string $name): array
    {
        preg_match_all('/\[([^\]]+)\]/', $name, $m);

        return [strtok($name, '['), ...$m[1]];
    }

    public function newBlock(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';
        if (preg_match(self::NAME, $name) !== 1) {
            return new Response(400, [], FolioText::get('folio.errors.invalid_field'));
        }
        $locale = is_string($query['locale'] ?? null) && $this->locales->has($query['locale']) ? $query['locale'] : $this->locales->default;
        $depth = max(0, (int) ($query['depth'] ?? 0));

        $blocks = $this->fieldTypes->get('blocks');
        $html = $blocks instanceof BlocksFieldType
            ? $blocks->renderNewCard((string) $request->getAttribute('key', ''), $name, $this->locales, $locale, $depth)
            : null;

        if ($html === null) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_block'));
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
