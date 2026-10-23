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
    /** How often validate() ran. */
    public static int $validations = 0;

    /** How often the costly check ran (like a throttled code lookup: never for a post that fails anyway). */
    public static int $lookups = 0;

    public function validate(array $input, ServerRequestInterface $request, array $baseErrors): array
    {
        self::$validations++;
        $company = trim((string) ($input['company'] ?? ''));
        if ($company === '') {
            return ['company' => 'Please name your company.'];
        }
        if ($baseErrors === []) {
            self::$lookups++;
        }

        return [];
    }

    public function recordValues(array $input): array
    {
        return ['company' => trim((string) ($input['company'] ?? ''))];
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
        return ['Company' => (string) $member->get('company')];
    }
}
