<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/**
 * The languages of a record at publishing. Publishing is the check of machine translations:
 * clearMachineMarks() takes the marks off the languages the record is visible in. With
 * publishing.complete_languages, incomplete() names the visible languages that lack texts the
 * default language has, and the record is not published. The default has no marks and finds
 * every language complete.
 */
interface LanguageChecks
{
    /** Clears the machine-translation marks of the languages $record is visible in, on $record itself. */
    public function clearMachineMarks(DynamicRecord $record): void;

    /**
     * The visible languages that lack texts, as validation errors keyed "_translations.<locale>"
     * with messages ready to show; [] when every language is complete.
     *
     * @return array<string, list<string>>
     */
    public function incomplete(DynamicRecord $record): array;
}
