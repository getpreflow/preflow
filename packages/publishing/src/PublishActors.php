<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * The markers publishing writes as "by" where no person acted (a revision's created_by, an
 * activity log row): the scheduler (Scheduler, `publish:scheduled`) and the command line
 * (`publish`). PublishingServiceProvider registers their labels with core's ActivityActors
 * (lang/{locale}/publishing.php), so a reader shows "Scheduled publishing", never a person.
 *
 * @since 0.77
 */
final class PublishActors
{
    public const string SCHEDULER = 'scheduler';

    public const string CLI = 'cli';

    /** @var array<string, string> marker => translation key */
    public const array LABELS = [
        self::SCHEDULER => 'publishing.activity.scheduler',
        self::CLI => 'publishing.activity.cli',
    ];
}
