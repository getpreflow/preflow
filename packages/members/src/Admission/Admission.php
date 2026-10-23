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
    /** details() for the owner's Decision panel in Folio (one line: "Registered … · label: value"). */
    public const string PANEL = 'panel';

    /** details() for the owner's notice mail (one paragraph per entry). */
    public const string NOTICE = 'notice';

    /**
     * Extra registration rules on the submitted input, asked on every post so its errors show
     * together with the form's own. $baseErrors are those of the form's fields (name, email,
     * password, consent; field => key): skip a costly or throttled check (a code lookup) while
     * it is not empty, the post fails anyway.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $baseErrors
     * @return array<string, string> field => message
     */
    public function validate(array $input, ServerRequestInterface $request, array $baseErrors): array;

    /**
     * Values to store on the new member record from the input (beyond email/name/password/language);
     * each key must be a field of the member type (a site adds fields with an "extends" model file).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordValues(array $input): array;

    /**
     * After the e-mail is confirmed: Decision::Accept (member now, signed in) or Decision::Pending
     * (owner decides). $request is the confirming request (e.g. to throttle a code redeem per client).
     */
    public function afterConfirm(DynamicRecord $member, ServerRequestInterface $request): Decision;

    /**
     * A line for the member right after the confirmation (asked after afterConfirm()), or null:
     * shown on the thanks page when the owner decides, or flashed as "members.notice" to the
     * landing when the member is accepted.
     */
    public function confirmNotice(DynamicRecord $member): ?string;

    /**
     * What the owner should know about the member, for the Decision panel ({@see PANEL}) or
     * the notice mail ({@see NOTICE}); the two may differ (a short "code: yes" in the panel,
     * the member's own words in the mail).
     *
     * @param self::PANEL|self::NOTICE $for
     * @return array<int|string, string> label => value; an entry with an int key is shown without a label
     */
    public function details(DynamicRecord $member, string $for): array;
}
