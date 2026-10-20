<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\Locales;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Server-rendered card fragments for the block picker (fetched by htmx). */
final class BlockEditorController
{
    /** A field name: "sections" or nested "sections[<uid>][<field>]…". */
    private const NAME = '/^[a-z_][a-z0-9_]*(\[[a-f0-9]{16}\]\[[a-z_][a-z0-9_]*\])*$/';

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly Locales $locales,
    ) {}

    public function newBlock(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';
        if (preg_match(self::NAME, $name) !== 1) {
            return new Response(400, [], 'Invalid field name');
        }
        $locale = is_string($query['locale'] ?? null) && $this->locales->has($query['locale']) ? $query['locale'] : $this->locales->default;
        $depth = max(0, (int) ($query['depth'] ?? 0));

        $blocks = $this->fieldTypes->get('blocks');
        $html = $blocks instanceof BlocksFieldType
            ? $blocks->renderNewCard((string) $request->getAttribute('key', ''), $name, $this->locales, $locale, $depth)
            : null;

        if ($html === null) {
            return new Response(404, [], 'Unknown block');
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
