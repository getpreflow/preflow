<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\I18n\Machine\TranslationWorker;

/** Whether the cron job that translates queued jobs has run lately; the screens that queue or list jobs say so when it has not. */
final class WorkerWatch
{
    /** Seconds without a worker run after which queued translations are taken to wait for nothing (cron runs it every minute). */
    private const SILENCE = 300;

    public static function runs(?TranslationWorker $worker): bool
    {
        $last = $worker?->lastRun();

        return $last !== null && $last->getTimestamp() >= time() - self::SILENCE;
    }
}
