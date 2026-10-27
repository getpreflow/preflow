<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Data\DynamicRecord;
use Preflow\Members\MemberLinks;
use Preflow\Members\MembersConfig;

/** The owner decides on the member's edit page in Folio (the Decision panel), linked from the notice mail. */
final class FolioMemberLinks implements MemberLinks
{
    /** @param string $baseUrl config app.url @param string $prefix Folio's path ("/folio") */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $prefix,
    ) {}

    public function decisionUrl(DynamicRecord $member): string
    {
        return rtrim($this->baseUrl, '/') . '/' . trim($this->prefix, '/') . '/' . MembersConfig::TYPE . '/' . rawurlencode((string) $member->getId()) . '/edit';
    }
}
