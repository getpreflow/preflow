<?php

declare(strict_types=1);

namespace Preflow\Members\Admission;

use Preflow\Data\DynamicRecord;
use Psr\Http\Message\ServerRequestInterface;

/** Everyone who confirms the e-mail is a member at once. No extra fields. */
final class AcceptOnConfirm implements Admission
{
    public function validate(array $input, ServerRequestInterface $request): array
    {
        return [];
    }

    public function recordValues(array $input): array
    {
        return [];
    }

    public function afterConfirm(DynamicRecord $member): Decision
    {
        return Decision::Accept;
    }

    public function details(DynamicRecord $member): array
    {
        return [];
    }
}
