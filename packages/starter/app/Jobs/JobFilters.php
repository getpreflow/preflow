<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * What a visitor asked for on the job list. Everything arrives from the address bar or a form,
 * so fromArray() never refuses: anything odd becomes the default, and no filter value can
 * cause an error page.
 */
final readonly class JobFilters
{
    public const array TYPES = ['full_time', 'part_time', 'contract', 'internship'];
    public const array REMOTE = ['no', 'hybrid', 'full'];
    public const array VIEWS = ['cards', 'list'];

    /** A page number this large is nonsense; the cap keeps offset arithmetic in integers. */
    private const int MAX_PAGE = 10000;

    public function __construct(
        public string $q = '',
        public ?string $place = null,
        public ?string $type = null,
        public ?string $remote = null,
        public string $view = 'cards',
        public int $page = 1,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $place = self::text($input['place'] ?? null, 80);
        $type = self::text($input['type'] ?? null, 20);
        $remote = self::text($input['remote'] ?? null, 20);
        $view = self::text($input['view'] ?? null, 20);
        $page = $input['page'] ?? 1;

        return new self(
            q: self::text($input['q'] ?? null, 100),
            place: $place === '' ? null : $place,
            type: in_array($type, self::TYPES, true) ? $type : null,
            remote: in_array($remote, self::REMOTE, true) ? $remote : null,
            view: in_array($view, self::VIEWS, true) ? $view : 'cards',
            page: is_int($page) || (is_string($page) && is_numeric($page))
                ? max(1, min(self::MAX_PAGE, (int) $page))
                : 1,
        );
    }

    /** Only what differs from the defaults, in the order the address shows it. */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'place' => $this->place,
            'type' => $this->type,
            'remote' => $this->remote,
            'view' => $this->view === 'cards' ? null : $this->view,
            'page' => $this->page === 1 ? null : $this->page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $override a null or empty value removes that key */
    public function url(string $path = '/jobs', array $override = []): string
    {
        $query = array_filter(
            array_merge($this->toQuery(), $override),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge([
            'q' => $this->q,
            'place' => $this->place,
            'type' => $this->type,
            'remote' => $this->remote,
            'view' => $this->view,
            'page' => $this->page,
        ], $changes));
    }

    /** A trimmed string, cut to $max characters; anything that is not a string is ''. */
    private static function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }
}
