<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Placement;
use Preflow\Data\Locales;
use Preflow\Folio\Field\AreasEditor;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AreasFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\FolioText;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Server-rendered fragments of the blocks editor (fetched by htmx): a new card, a duplicate, and
 * (0.92) a page's areas after a layout switch or a move (areas()).
 */
final class BlockEditorController
{
    /**
     * A block list's name: a field "sections", a page's area "_areas[<area>]" (0.92), then nested
     * "[<uid>][<field>]" and an areas field's column "[areas][<area>]", in any depth.
     */
    private const NAME = '/^(?:_areas\[[A-Za-z_][A-Za-z0-9_]*\]|[A-Za-z_][A-Za-z0-9_]*)(?:\[[a-f0-9]{16}\]\[[A-Za-z_][A-Za-z0-9_]*\]|\[areas\]\[[A-Za-z_][A-Za-z0-9_]*\])*$/';

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly Locales $locales,
        private readonly ?\Preflow\Data\DataManager $dm = null,
        private readonly ?\Preflow\Folio\Content\TypeCatalog $catalog = null,
        /** The types' page layouts (0.92; bound in preflow/blocks' boot); without, areas() finds none. */
        private readonly ?PageLayouts $layouts = null,
        private readonly ?AreasEditor $areasEditor = null,
        /** Asked for the edit (create, for a new record) permission of the type a request names (0.92); null: everything allowed. */
        private readonly ?\Preflow\Auth\Access\Access $access = null,
        /** The admin's 403 page; null answers a plain 403. */
        private readonly ?\Preflow\View\TemplateEngineInterface $engine = null,
        private readonly string $prefix = '/folio',
    ) {}

    /**
     * The 403 for a request naming a type ("type", with "id" for a stored record) the user may not
     * edit (or, without "id", create); null when allowed. The route only asks for some type's edit
     * or create, and these endpoints read the stored record of the type named.
     */
    private function refuse(ServerRequestInterface $request, string $type, string $id): ?ResponseInterface
    {
        if ($this->access === null || $this->access->can($type . ($id !== '' ? '.edit' : '.create'))) {
            return null;
        }

        return $this->engine !== null && $this->catalog !== null
            ? Forbidden::response($request, $this->engine, $this->prefix, $this->catalog)
            : new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8'], FolioText::get('folio.errors.forbidden'));
    }

    /**
     * A page's areas after a layout switch or a move (POST {admin}/_areas, 0.92), built from the
     * posted form; nothing is stored. Query: "name" ("_areas"; an areas field's name with "set" is
     * the areas field's), "type", "id" (empty for a new page), "locale", "depth". Body: the record
     * form; "_areas_to", the layout chosen (else the posted "_layout", the picker's own value); or
     * "_areas_move" = "<uid>:<area>", that block moved to the end of an area of the layout. Each
     * posted area is built as a blocks field over the stored one (BlocksFieldType::buildValue(), no
     * file written); areas not posted keep their stored blocks; nothing is dropped: an area the
     * layout lacks goes to the panel "Not shown in this layout". Answers the areas section
     * (AreasEditor::SWAP) for htmx to swap in. A stored key that is no longer available is the
     * picker's value until the editor chooses a layout, so a move keeps its notice and a switch to
     * another layout clears it.
     */
    public function areas(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';
        $type = is_string($query['type'] ?? null) ? $query['type'] : '';
        $id = is_string($query['id'] ?? null) ? $query['id'] : '';
        if (isset($query['set'])) {
            // An areas field anywhere, also in a block of a page's area ("_areas[main][<uid>][columns]");
            // never the page's own list ("_areas", "_areas[<area>]"), which is no areas field.
            if (!is_string($query['set']) || preg_match(self::NAME, $name) !== 1 || preg_match('/^' . PageLayouts::AREAS . '(?:\[[A-Za-z_][A-Za-z0-9_]*\])?$/', $name) === 1) {
                return new Response(400, [], FolioText::get('folio.errors.invalid_field'));
            }
            if ($type !== '' && ($refused = $this->refuse($request, $type, $id)) !== null) {
                return $refused;
            }

            return $this->fieldAreas($request, $name, $query['set'], $type, $id);
        }
        if ($name !== PageLayouts::AREAS) {
            return new Response(400, [], FolioText::get('folio.errors.invalid_field'));
        }
        if ($type !== '' && ($refused = $this->refuse($request, $type, $id)) !== null) {
            return $refused;
        }
        $blocks = $this->fieldTypes->has('blocks') ? $this->fieldTypes->get('blocks') : null;
        $typeLayouts = $type !== '' && $this->catalog?->has($type) ? $this->layouts?->forType($type) : null;
        $registry = $this->dm?->getTypeRegistry();
        if ($typeLayouts === null || $registry === null || $this->areasEditor === null || !$blocks instanceof BlocksFieldType) {
            return NotFound::response($request, FolioText::get('folio.errors.unknown_type'));
        }
        $def = $registry->get($type);
        $locale = is_string($query['locale'] ?? null) && $def->locales->has($query['locale']) ? $query['locale'] : $def->locales->default;
        $stored = $id !== '' ? ($this->dm->findType($type, $id)?->toArray() ?? []) : [];
        $body = (array) $request->getParsedBody();

        // The areas as the form holds them: each posted one over the stored one.
        $areas = Placement::areasOf($stored[PageLayouts::AREAS] ?? null);
        $posted = is_array($body[PageLayouts::AREAS] ?? null) ? $body[PageLayouts::AREAS] : [];
        $ctx = new BuildContext($def->locales, $locale, false);
        foreach ($posted as $area => $post) {
            if (is_string($area) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $area) === 1 && is_array($post)) {
                $areas[$area] = $blocks->buildValue($post, $areas[$area] ?? [], [], $ctx);
            }
        }

        $move = is_string($body['_areas_move'] ?? null) ? explode(':', $body['_areas_move'], 2) : null;
        // The layout: the one chosen, else the form's (a pinned page posts none: the stored one).
        $layout = $body['_areas_to'] ?? $body[PageLayouts::LAYOUT] ?? $stored[PageLayouts::LAYOUT] ?? null;
        $layout = is_string($layout) && $layout !== '' ? $layout : null;
        $values = $stored;
        $slugField = $typeLayouts->slugField;
        if (is_string($body[$slugField] ?? null) && $locale === $def->locales->default) {
            $values[$slugField] = $body[$slugField]; // a pin follows the slug as typed
        }
        $values[PageLayouts::LAYOUT] = $layout;
        $values[PageLayouts::AREAS] = $areas;
        if ($move !== null && count($move) === 2) {
            [$uid, $area] = $move;
            $current = $this->layouts->resolveValues($def, $values)?->layout;
            if (preg_match('/^[a-f0-9]{16}$/', $uid) === 1 && isset($current?->areas[$area])) {
                $values[PageLayouts::AREAS] = Placement::moved($areas, $uid, $area);
            }
        }

        $html = $this->areasEditor->renderPage($this->layouts, $def, $values, $locale, [], ['type' => $type, 'id' => $id !== '' ? $id : null], AreasEditor::SWAP);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /**
     * An areas field's editor after a layout switch or a move (POST {admin}/_areas with "set", 0.92):
     * the field's value as the form holds it (posted under "name": "<name>[layout]",
     * "<name>[areas][<area>]"), over its stored value (the record's field, or the field of the block
     * at that path; read only for someone who may edit the type), built as the save builds it
     * (AreasFieldType::buildValue(), no file written); "_areas_to" a layout chosen, "_areas_move"
     * "<uid>:<area>" a block moved to the end of an area of the layout. Nothing is stored or
     * dropped: a column the layout lacks goes to the panel "Not shown in this layout". Answers the
     * whole editor (AreasEditor::WHOLE) for htmx to swap in.
     */
    private function fieldAreas(ServerRequestInterface $request, string $name, string $set, string $type, string $id): ResponseInterface
    {
        $areasType = $this->fieldTypes->has('areas') ? $this->fieldTypes->get('areas') : null;
        $blocks = $this->fieldTypes->has('blocks') ? $this->fieldTypes->get('blocks') : null;
        if (!$areasType instanceof AreasFieldType || !$blocks instanceof BlocksFieldType) {
            return NotFound::response($request, FolioText::get('folio.errors.invalid_field'));
        }
        $config = ['layouts' => $set];
        try {
            $layouts = $areasType->layoutSet($config);
        } catch (\Preflow\Blocks\Layouts\LayoutConfigException) {
            return NotFound::response($request, FolioText::get('folio.errors.invalid_field')); // a set no package registers
        }
        $query = $request->getQueryParams();
        $locales = $this->locales;
        $stored = null;
        $record = null;
        $keys = self::keys($name);
        if ($type !== '' && $this->catalog?->has($type) && $this->dm !== null) {
            $locales = $this->dm->getTypeRegistry()?->get($type)->locales ?? $locales;
            $record = ['type' => $type, 'id' => $id !== '' ? $id : null];
            $existing = $id !== '' ? $this->dm->findType($type, $id) : null;
            $stored = $existing !== null ? $blocks->storedLevel($existing->toArray(), $keys) : null;
        }
        $locale = is_string($query['locale'] ?? null) && $locales->has($query['locale']) ? $query['locale'] : $locales->default;
        $depth = max(0, (int) ($query['depth'] ?? 0));

        $body = (array) $request->getParsedBody();
        $posted = $body;
        foreach ($keys as $key) {
            $posted = is_array($posted[$key] ?? null) ? $posted[$key] : [];
        }
        $value = \Preflow\Blocks\Layouts\AreasValue::fromStored($areasType->buildValue($posted, $stored, [], new BuildContext($locales, $locale, false, $depth), $config));
        $layout = is_string($body['_areas_to'] ?? null) && $body['_areas_to'] !== '' ? $body['_areas_to'] : $value->layout;
        $areas = $value->areas;
        $move = is_string($body['_areas_move'] ?? null) ? explode(':', $body['_areas_move'], 2) : [];
        if (count($move) === 2 && preg_match('/^[a-f0-9]{16}$/', $move[0]) === 1) {
            $current = \Preflow\Blocks\Layouts\AreasValue::of($layout, $areas)->resolve($layouts);
            if (isset($current?->areas[$move[1]])) {
                $areas = Placement::moved($areas, $move[0], $move[1]);
            }
        }

        $html = $areasType->renderAreas($name, \Preflow\Blocks\Layouts\AreasValue::of($layout, $areas)->toStored(), $config, $locales, $locale, $depth, [], $record);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

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
        // The stored block is read from the record of the type named: only for someone who may edit it (0.92).
        if ($type !== '' && ($refused = $this->refuse($request, $type, $id)) !== null) {
            return $refused;
        }
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
            ? NotFound::response($request, FolioText::get('folio.errors.nothing_to_duplicate'))
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
            return NotFound::response($request, FolioText::get('folio.errors.unknown_block'));
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
