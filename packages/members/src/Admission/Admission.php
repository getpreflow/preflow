<?php

declare(strict_types=1);

namespace Preflow\Members\Admission;

use Preflow\Data\DynamicRecord;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who gets in after registering: the site's rule (config/members.php "admission").
 * A member is the record of the member type (DynamicRecord, type "member").
 */
interface Admission
{
    /**
     * Extra registration rules on the submitted input.
     *
     * @param array<string, mixed> $input
     * @return array<string, string> field => message
     */
    public function validate(array $input, ServerRequestInterface $request): array;

    /**
     * Values to store on the new member record from the input (beyond email/name/password/language);
     * each key must be a field of the member type (a site adds fields with an "extends" model file).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordValues(array $input): array;

    /** After the e-mail is confirmed: Decision::Accept (member now, signed in) or Decision::Pending (owner decides). */
    public function afterConfirm(DynamicRecord $member): Decision;

    /**
     * Lines for the owner's notice mail and the decision panel.
     *
     * @return array<string, string> label => value
     */
    public function details(DynamicRecord $member): array;
}
