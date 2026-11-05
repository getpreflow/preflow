<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * One thing the SEO audit found: which check, how bad, what is wrong and what to
 * do, the page (URL and locale; empty for a site-wide finding) and, for content,
 * where to fix it. Built-in and site checks report in this same shape.
 *
 * @since 0.54
 */
final readonly class Finding
{
    public function __construct(
        public string $check,
        public Severity $severity,
        public string $message,
        public string $url = '',
        public string $locale = '',
        public ?Target $target = null,
    ) {}

    /** @return array{check: string, severity: string, message: string, url: string, locale: string, target: array{kind: string, type: string, id: string}|null} */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'url' => $this->url,
            'locale' => $this->locale,
            'target' => $this->target === null ? null : [
                'kind' => $this->target->kind,
                'type' => $this->target->type,
                'id' => $this->target->id,
            ],
        ];
    }

    /** @param array<string, mixed> $data what toArray() gave (e.g. read back from the report) */
    public static function fromArray(array $data): self
    {
        $target = $data['target'] ?? null;

        return new self(
            (string) ($data['check'] ?? ''),
            Severity::from((string) ($data['severity'] ?? '')),
            (string) ($data['message'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['locale'] ?? ''),
            is_array($target)
                ? new Target((string) ($target['kind'] ?? ''), (string) ($target['type'] ?? ''), (string) ($target['id'] ?? ''))
                : null,
        );
    }
}
