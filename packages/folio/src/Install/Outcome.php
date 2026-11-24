<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

use Preflow\DevTools\Config\ConfigEdit;

/**
 * What one folio:install step did: a config file written, changed or already set up, an edit
 * refused (with the snippet to add by hand), a command run or failed, a step skipped, or, with
 * --dry-run, what it would do.
 *
 * @since 0.85.0
 */
final readonly class Outcome
{
    public const string WRITTEN = 'written';
    public const string CHANGED = 'changed';
    public const string ALREADY = 'already';
    public const string REFUSED = 'refused';
    public const string SKIPPED = 'skipped';
    public const string RAN = 'ran';
    public const string FAILED = 'failed';
    public const string PLANNED = 'planned';

    /**
     * @param string $subject what the step is about: a file (config/auth.php), a command or "first admin"
     * @param string|null $snippet the text to add by hand (refused), or what it would write (planned)
     * @param string $output what a command printed
     */
    public function __construct(
        public string $status,
        public string $subject,
        public string $message,
        public ?string $snippet = null,
        public string $output = '',
    ) {}

    /**
     * A ConfigFileEditor result as an outcome for config/$file: $done says what was written or
     * added, $already what is there when nothing was to do (default: the editor's message).
     */
    public static function fromEdit(ConfigEdit $edit, string $file, string $done, ?string $already = null): self
    {
        $subject = 'config/' . $file;

        return match ($edit->status) {
            ConfigEdit::WRITTEN, ConfigEdit::CHANGED => new self($edit->status, $subject, $done),
            ConfigEdit::ALREADY => new self(self::ALREADY, $subject, $already ?? $edit->message),
            default => new self(self::REFUSED, $subject, $edit->message, $edit->snippet),
        };
    }

    /** Whether the step needs the developer: a refused edit or a failed command. */
    public function needsYou(): bool
    {
        return $this->status === self::REFUSED || $this->status === self::FAILED;
    }
}
