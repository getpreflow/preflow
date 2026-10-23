<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Fixtures;

use Preflow\Data\DynamicRecord;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Psr\Http\Message\ServerRequestInterface;

/** A site rule for the tests: a company is required at registration and stored on the member; the owner decides. */
final class CompanyAdmission implements Admission
{
    public function validate(array $input, ServerRequestInterface $request): array
    {
        return trim((string) ($input['company'] ?? '')) === '' ? ['company' => 'Please name your company.'] : [];
    }

    public function recordValues(array $input): array
    {
        return ['company' => trim((string) ($input['company'] ?? ''))];
    }

    public function afterConfirm(DynamicRecord $member): Decision
    {
        return Decision::Pending;
    }

    public function details(DynamicRecord $member, string $for): array
    {
        return ['Company' => (string) $member->get('company')];
    }
}
