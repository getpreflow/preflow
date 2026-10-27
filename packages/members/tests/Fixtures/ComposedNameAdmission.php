<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Fixtures;

use Preflow\Data\DynamicRecord;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\ComposesName;
use Preflow\Members\Admission\Decision;
use Psr\Http\Message\ServerRequestInterface;

/** A site rule for the tests: first and last name instead of one name field; the owner decides. */
final class ComposedNameAdmission implements Admission, ComposesName
{
    /** @var list<array<string, mixed>> the input validate() saw */
    public static array $seen = [];

    public function name(array $input): string
    {
        return trim(trim((string) ($input['first_name'] ?? '')) . ' ' . trim((string) ($input['last_name'] ?? '')));
    }

    public function validate(array $input, ServerRequestInterface $request, array $baseErrors): array
    {
        self::$seen[] = $input;

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
