<?php

declare(strict_types=1);

namespace App\Components\Blocks\LatestNews;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;
use Preflow\Data\TypeFamily;

/** The newest published news posts as teasers, with a link to /news. */
#[Block(key: 'latest_news', label: 'Latest news', group: 'News', description: 'The newest published news posts', icon: 'list', family: TypeFamily::Live)]
final class LatestNews extends BlockComponent
{
    protected string $tag = 'section';
    protected string $cssClass = 'latest-news';
    protected bool $scopeCss = true;

    #[BlockField(type: 'string')]
    public string $title = 'News';

    #[BlockField(type: 'number', help: 'How many posts to show (1 to 12). Empty shows 3.')]
    public int $count = 3;

    public function resolveState(): void
    {
        $this->count = $this->count > 0 ? min(12, $this->count) : 3;
    }
}
