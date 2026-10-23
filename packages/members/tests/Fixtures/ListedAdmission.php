<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Fixtures;

use Preflow\Data\DynamicRecord;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Psr\Http\Message\ServerRequestInterface;

/** A site rule for the tests: the owner decides, and the panel lists two lines about the member. */
final class ListedAdmission implements Admission
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
        return Decision::Pending;
    }

    public function details(DynamicRecord $member): array
    {
        return ['book code' => 'no', 'email' => (string) $member->get('email')];
    }
}
