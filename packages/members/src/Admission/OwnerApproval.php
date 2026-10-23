<?php

declare(strict_types=1);

namespace Preflow\Members\Admission;

use Preflow\Data\DynamicRecord;
use Psr\Http\Message\ServerRequestInterface;

/** Everyone who confirms the e-mail waits for the owner's decision in Folio. No extra fields. */
final class OwnerApproval implements Admission
{
    public function validate(array $input, ServerRequestInterface $request, array $baseErrors): array
    {
        return [];
    }

    public function recordValues(array $input): array
    {
        return [];
    }

    public function afterConfirm(DynamicRecord $member, ServerRequestInterface $request): Decision
    {
        return Decision::Pending;
    }

    public function confirmNotice(DynamicRecord $member): ?string
    {
        return null;
    }

    public function details(DynamicRecord $member, string $for): array
    {
        return [];
    }
}
