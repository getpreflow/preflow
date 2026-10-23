<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Fixtures;

use Preflow\Data\DynamicRecord;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Psr\Http\Message\ServerRequestInterface;

/** A site rule for the tests: "accept@…" is accepted, everyone else waits; both get a confirm notice. */
final class NoticeAdmission implements Admission
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
        return str_starts_with((string) $member->get('email'), 'accept@') ? Decision::Accept : Decision::Pending;
    }

    public function confirmNotice(DynamicRecord $member): ?string
    {
        return 'Your code no longer works for ' . $member->get('email') . '.';
    }

    public function details(DynamicRecord $member, string $for): array
    {
        return [];
    }
}
