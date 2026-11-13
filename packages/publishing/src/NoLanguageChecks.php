<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** The default LanguageChecks: no machine marks, every language complete. */
final class NoLanguageChecks implements LanguageChecks
{
    public function clearMachineMarks(DynamicRecord $record): void {}

    public function incomplete(DynamicRecord $record): array
    {
        return [];
    }

    public function stateKeys(): array
    {
        return [];
    }
}
