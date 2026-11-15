<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * Names for the markers that stand as Activity::$by where no person acted: the package that
 * writes a marker registers it with a label (a translation key, or plain text). A reader asks
 * label() before it looks the value up as a user: a marker is never a person, and its label
 * reads in the reader's language ("Account deletion" for members' "member-deletion").
 *
 * Core binds one instance (the container's ActivityActors); a provider registers in register()
 * or boot().
 *
 * @since 0.77
 */
final class ActivityActors
{
    /** @var array<string, string> marker => label (a translation key or text) */
    private array $labels = [];

    /**
     * @param string $marker the value written as Activity::$by ("member-deletion"); never a user id
     * @param string $label  a translation key ("members.activity.account_deletion") or plain text
     */
    public function register(string $marker, string $label): void
    {
        if ($marker === '' || $label === '') {
            throw new \InvalidArgumentException('An activity actor marker and its label must not be empty.');
        }
        $this->labels[$marker] = $label;
    }

    /** The marker's label (a translation key or text), null when $by is no registered marker. */
    public function label(string $by): ?string
    {
        return $this->labels[$by] ?? null;
    }

    /** @return array<string, string> marker => label */
    public function all(): array
    {
        return $this->labels;
    }
}
