<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

/**
 * The registration form's trap for bots: a text field people never see or reach
 * (templates/_trap.twig), which bots fill in like every other field. A registration with it
 * filled in creates nobody and sends no mail, but answers as a registration does, so a bot
 * learns nothing. A site with its own register form includes the partial in it:
 * {% include '@members-package/_trap.twig' %}.
 */
final class Trap
{
    public const string FIELD = 'members_website';

    /** @param array<string, mixed> $body */
    public static function caught(array $body): bool
    {
        return Input::text($body, self::FIELD) !== '';
    }
}
