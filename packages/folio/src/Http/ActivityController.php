<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Dashboard\RecentChanges;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET {prefix}/activity (All activity, the dashboard's "Recent changes" in full): the activity log
 * the signed-in person may see (RecentChanges keeps to {type}.view, assets.view and
 * redirects.manage), PER_PAGE rows a page by the log's cursor (?before=), filtered by ?type= (a
 * subject of theirs; anything else is no filter) and ?by= (a person). FolioPermissionMiddleware asks
 * folio.dashboard, as for the dashboard that links here: no permission of its own.
 *
 * @since 0.77
 */
final class ActivityController
{
    public const int PER_PAGE = 50;

    /** @param \Closure(string): bool $can */
    public function __construct(
        private readonly RecentChanges $recent,
        private readonly TemplateEngineInterface $engine,
        private readonly \Closure $can,
        private readonly string $prefix,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $param = static fn (string $name): string => is_string($query[$name] ?? null) ? trim(mb_substr($query[$name], 0, 200)) : '';
        $subjects = $this->recent->subjects($this->can);
        $type = in_array($param('type'), array_column($subjects, 'key'), true) ? $param('type') : '';
        $by = $param('by');
        $before = $param('before');

        $page = $this->recent->page($this->can, self::PER_PAGE, $type !== '' ? $type : null, $by !== '' ? $by : null, $before !== '' ? $before : null);
        $people = $this->recent->people($this->can, $type !== '' ? $type : null);
        if ($by !== '' && !in_array($by, array_column($people, 'id'), true)) {
            $people[] = ['id' => $by, 'label' => $by]; // the filter stays selectable while it finds nothing
        }
        $filters = array_filter(['type' => $type, 'by' => $by], static fn (string $v): bool => $v !== '');

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/activity.twig', [
            'prefix' => $this->prefix,
            'rows' => $page['rows'],
            'subjects' => $subjects,
            'people' => $people,
            'type' => $type,
            'by' => $by,
            'filtered' => $filters !== [] || $before !== '',
            'failed' => $page['failed'],
            'newest_url' => $before !== '' ? $this->url($filters) : null,
            'older_url' => $page['next'] !== null ? $this->url($filters + ['before' => $page['next']]) : null,
        ]));
    }

    /** @param array<string, string> $params */
    private function url(array $params): string
    {
        return $this->prefix . '/activity' . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
}
