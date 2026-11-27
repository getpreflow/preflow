<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Draft\DraftFailed;
use Preflow\Ai\Draft\DraftLength;
use Preflow\Ai\Draft\DraftRequest;
use Preflow\Ai\Draft\DraftWriter;
use Preflow\Ai\Limits\AiLimitReached;
use Preflow\Ai\Limits\AiLimits;
use Preflow\Ai\Limits\AiLimitsMisconfigured;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Ai\CostFormat;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\Types\RichTextFieldType;
use Preflow\Folio\FolioText;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * "Write a draft" in rich text (ai-draft.js): POST {prefix}/_ai/draft with type, id (the record's;
 * empty with new=1 on the create form), field (the field's label as the form shows it), brief,
 * length (short|medium|long), language and title (optional). Answers {html, cost}: the draft,
 * sanitised here, since model HTML is never trusted, and its cost as Folio shows it (null: unknown).
 * The draft asks only for the elements Folio's Trix editor keeps (RichTextFieldType::EDITOR_ELEMENTS).
 * Nothing is saved: the editor puts the draft into the field, an ordinary edit the person saves
 * (or autosave sends) as any other.
 *
 * The route exists only while the draft writer is available (FolioRoutes); the check here covers a
 * writer that became unavailable after boot. The route asks ai.draft (FolioPermissionMiddleware);
 * this controller adds the type's edit permission for an existing record, its create permission
 * for a new one (new=1 with no id; an id must name a record). Errors carry Folio's own text
 * (folio.ai_draft.errors.*): 503 when the writer cannot be used at all (AiUnavailable), 502 when
 * this draft failed, 422 for bad input, 404 for an unknown type or record. What the provider said
 * goes to the log (the app's PSR-3 logger, else the PHP log), never to the page.
 *
 * Each draft counts against the signed-in person's `draft` limit (AiLimits, since 0.78), after the
 * input checks: over it, 429 with Retry-After and the limit's text (ai.limits.draft).
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
        /** What cleans the draft's HTML: the core's cleaner (since 0.93, Folio gives the DataManager's); a Symfony sanitizer still works. */
        private readonly HtmlSanitizerInterface|\Preflow\Data\Html\HtmlCleaner $sanitizer,
        private readonly DataManager $dm,
        private readonly ?Access $access = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AiLimits $limits = null,
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
            return self::error(404, 'unknown_type');
        }
        $id = self::text($body, 'id');
        $new = self::text($body, 'new') === '1';
        if ($new && $id !== '') {
            return self::error(422, 'new_with_id');
        }
        if (!$new && $id === '') {
            return self::error(422, 'no_record');
        }
        if (!($this->access?->can('ai.draft') ?? true) || !($this->access?->can($type . ($new ? '.create' : '.edit')) ?? true)) {
            return self::error(403, 'forbidden');
        }
        if (!$new && $this->dm->findType($type, $id) === null) {
            return self::error(404, 'not_found');
        }
        $brief = trim(self::text($body, 'brief'));
        if ($brief === '' || mb_strlen($brief) > self::BRIEF_MAX) {
            return self::error(422, 'brief', ['max' => self::BRIEF_MAX]);
        }
        $length = DraftLength::tryFrom(self::text($body, 'length'));
        if ($length === null) {
            return self::error(422, 'length');
        }
        $language = self::text($body, 'language');
        if (!$this->registry->get($type)->locales->has($language)) {
            return self::error(422, 'language');
        }

        $person = $request->getAttribute(Authenticatable::class);
        if ($this->limits !== null && $person instanceof Authenticatable) {
            try {
                $this->limits->take(AiLimits::DRAFT, $person->getAuthId());
            } catch (AiLimitReached $e) {
                return self::json(429, ['error' => FolioText::get($e->textKey(), $e->textParams())], ['Retry-After' => (string) $e->retryAfter]);
            } catch (AiLimitsMisconfigured $e) {
                // The site's setup (APP_KEY), not the person's limit: 503 with the text that names the setting.
                return self::json(503, ['error' => $e->getMessage()]);
            }
        }

        LongRequest::allow($request, self::TIME_LIMIT);
        try {
            $draft = $this->writer->write(new DraftRequest($brief, $language, $length, [
                'Content type' => $listing->label,
                'Title' => self::short(self::text($body, 'title')),
                'Field' => self::short(self::text($body, 'field')),
            ], RichTextFieldType::EDITOR_ELEMENTS));
        } catch (AiUnavailable $e) {
            $this->log('AI is not available: ' . $e->getMessage());

            return self::error(503, 'unavailable');
        } catch (DraftFailed $e) {
            $this->log('the draft failed: ' . $e->getMessage());

            return self::error(502, 'failed');
        }

        $html = trim($this->sanitizer instanceof HtmlSanitizerInterface ? $this->sanitizer->sanitize($draft->html) : $this->sanitizer->clean($draft->html));
        if (trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
            return self::error(502, 'empty');
        }

        return self::json(200, ['html' => $html, 'cost' => CostFormat::usd($draft->costUsd)]);
    }

    /** What the provider said, for whoever runs the site. */
    private function log(string $message): void
    {
        $message = 'Folio "Write a draft": ' . $message;
        if ($this->logger !== null) {
            $this->logger->warning($message);

            return;
        }
        error_log($message);
    }

    /** @param array<string, scalar> $params */
    private static function error(int $status, string $key, array $params = []): ResponseInterface
    {
        return self::json($status, ['error' => FolioText::get('folio.ai_draft.errors.' . $key, $params)]);
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
     * @param array<string, string> $headers more headers (Retry-After)
     */
    private static function json(int $status, array $data, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'] + $headers,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
