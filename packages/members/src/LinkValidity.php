<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\I18n\Translator;

/**
 * How long a confirm or reset link works, in words ("24 hours", "eine Stunde"), from
 * confirm_ttl / reset_ttl and the lang keys members.validity.{hours,minutes}: the mails'
 * {validity} and the check-inbox page's :validity, so a changed TTL never contradicts the copy.
 */
final class LinkValidity
{
    public function __construct(
        private readonly Translator $translator,
        private readonly MembersConfig $config,
    ) {}

    public function confirm(string $locale): string
    {
        return $this->format($this->config->confirmTtl(), $locale);
    }

    public function reset(string $locale): string
    {
        return $this->format($this->config->resetTtl(), $locale);
    }

    /** Whole hours as hours, anything else as minutes (at least one). */
    public function format(int $seconds, string $locale): string
    {
        [$key, $count] = $seconds >= 3600 && $seconds % 3600 === 0
            ? ['hours', intdiv($seconds, 3600)]
            : ['minutes', max(1, (int) ceil($seconds / 60))];

        return $this->translator->choice('members.validity.' . $key, $count, [], $locale);
    }
}
