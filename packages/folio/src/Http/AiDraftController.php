<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Draft\DraftFailed;
use Preflow\Ai\Draft\DraftLength;
use Preflow\Ai\Draft\DraftRequest;
use Preflow\Ai\Draft\DraftWriter;
use Preflow\Auth\Access\Access;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Ai\CostFormat;
use Preflow\Folio\Content\TypeCatalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * "Write a draft" in rich text (ai-draft.js): POST {prefix}/_ai/draft with type, field (the field's
 * label as the form shows it), brief, length (short|medium|long), language and title (optional).
 * Answers {html, cost}: the draft, sanitised here, since model HTML is never trusted, and its cost
 * as Folio shows it (null: unknown). Nothing is saved: the editor puts the draft into the field, an
 * ordinary edit the person saves (or autosave sends) as any other.
 *
 * The route exists only while the draft writer is available (FolioRoutes); the check here covers a
 * writer that became unavailable after boot. The route asks ai.draft (FolioPermissionMiddleware);
 * this controller adds the posted type's edit permission. 503 when the writer cannot be used at all
 * (AiUnavailable), 502 when this draft failed, 422 for bad input.
 *
 * @since 0.77
 */
final class AiDraftController
{
    /** Seconds a draft may take: two tries of the provider clients' 120 s timeout do not fit, one does with room. */
    private const int TIME_LIMIT = 180;

    /** The longest brief, in characters. */
    public const int BRIEF_MAX = 2000;

    /** The longest title or field label taken as context, in characters. */
    private const int CONTEXT_MAX = 200;

    public function __construct(
        private readonly DraftWriter $writer,
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly ?Access $access = null,
    ) {}

    public function draft(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->writer->available()) {
            return NotFound::response($request, 'Not found');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $type = self::text($body, 'type');
        $listing = $this->catalog->get($type);
        if ($listing === null) {
            return self::json(404, ['error' => 'Unknown type.']);
        }
        if (!($this->access?->can('ai.draft') ?? true) || !($this->access?->can($type . '.edit') ?? true)) {
            return self::json(403, ['error' => 'Forbidden.']);
        }
        $brief = trim(self::text($body, 'brief'));
        if ($brief === '' || mb_strlen($brief) > self::BRIEF_MAX) {
            return self::json(422, ['error' => 'Say in up to ' . self::BRIEF_MAX . ' characters what the draft should say.']);
        }
        $length = DraftLength::tryFrom(self::text($body, 'length'));
        if ($length === null) {
            return self::json(422, ['error' => 'The length is "short", "medium" or "long".']);
        }
        $language = self::text($body, 'language');
        if (!$this->registry->get($type)->locales->has($language)) {
            return self::json(422, ['error' => 'Choose a language of this content type.']);
        }

        LongRequest::allow($request, self::TIME_LIMIT);
        try {
            $draft = $this->writer->write(new DraftRequest($brief, $language, $length, [
                'Content type' => $listing->label,
                'Title' => self::short(self::text($body, 'title')),
                'Field' => self::short(self::text($body, 'field')),
            ]));
        } catch (AiUnavailable $e) {
            return self::json(503, ['error' => $e->getMessage()]);
        } catch (DraftFailed $e) {
            return self::json(502, ['error' => $e->getMessage()]);
        }

        $html = trim($this->sanitizer->sanitize($draft->html));
        if (trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
            return self::json(502, ['error' => 'The answer held no text that can go into the field.']);
        }

        return self::json(200, ['html' => $html, 'cost' => CostFormat::usd($draft->costUsd)]);
    }

    /** @param array<string, mixed> $body */
    private static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function short(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::CONTEXT_MAX ? mb_substr($text, 0, self::CONTEXT_MAX) : $text;
    }

    /**
     * The answer carries model output, which is not always valid UTF-8: a broken byte becomes
     * U+FFFD instead of a failed response.
     *
     * @param array<string, mixed> $data
     */
    private static function json(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
