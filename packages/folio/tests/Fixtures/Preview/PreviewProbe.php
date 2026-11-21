<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;
use Preflow\Core\Exceptions\HttpException;

/**
 * A component of the preview tests' page: it shows the post's title as the render reads it, loads
 * itself lazily, has a member-only action and one that writes. What it reads and writes comes from
 * the test (the hooks), so it needs no services of its own.
 */
final class PreviewProbe extends Component
{
    /** @var (\Closure(): string)|null the post's title as the current render reads it */
    public static ?\Closure $title = null;

    /** @var (\Closure(): bool)|null whether the current viewer may see the member area */
    public static ?\Closure $member = null;

    /** @var (\Closure(): void)|null a data write */
    public static ?\Closure $write = null;

    public string $heading = '';

    public string $said = '';

    public function resolveState(): void
    {
        $this->heading = self::$title !== null ? (self::$title)() : '';
    }

    public function actions(): array
    {
        return ['members', 'save'];
    }

    public function actionMembers(array $params = []): void
    {
        if (self::$member === null || !(self::$member)()) {
            throw new HttpException(403, 'Members only.');
        }
        $this->said = 'welcome, member';
    }

    public function actionSave(array $params = []): void
    {
        if (self::$write !== null) {
            (self::$write)();
        }
        $this->said = 'saved';
    }
}
