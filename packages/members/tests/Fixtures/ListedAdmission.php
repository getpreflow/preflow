<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Fixtures;

use Preflow\Data\DynamicRecord;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Psr\Http\Message\ServerRequestInterface;

/** A site rule for the tests: the owner decides; the panel lists two lines, the owner's notice two others (one without a label). */
final class ListedAdmission implements Admission
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
        return $for === self::NOTICE
            ? ['Book code' => 'given, but it no longer worked.', '“I build things”']
            : ['book code' => 'no', 'email' => (string) $member->get('email')];
    }
}
