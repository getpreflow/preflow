<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Fixtures\Freshness;

use Preflow\Components\Component;
use Preflow\Components\Freshness;
use Preflow\Components\Live;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness as Dial;

/** Reads its props' "tag" in resolveState(); the test engine reads "render:{tag}" while rendering. */
abstract class Reader extends Component
{
    public static ?DependencyRecorder $recorder = null;

    public string $state = '';

    public function resolveState(): void
    {
        $this->state = (string) ($this->props['tag'] ?? '');
        self::$recorder?->record($this->state);
    }
}

#[Freshness(Dial::NextVisit)]
class OtherJobs extends Reader
{
}

#[Freshness(Dial::Timed, seconds: 43200)]
final class Ticker extends Reader
{
}

/** Inherits OtherJobs' freshness. */
final class MoreJobs extends OtherJobs
{
}

final class PlainReader extends Reader
{
}

#[Live]
#[Freshness(Dial::NextVisit)]
final class LiveReader extends Reader
{
}

#[Freshness(Dial::Timed)]
final class TimedWithoutSeconds extends Reader
{
}
