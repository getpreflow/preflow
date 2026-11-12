<?php

declare(strict_types=1);

namespace App\Components\Blocks\Hero;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A page's opening: the headline (the page's h1), a short intro, an optional image and button. */
#[Block(key: 'hero', label: 'Hero', group: 'Sections', description: 'Headline, intro, image and a button')]
final class Hero extends BlockComponent
{
    protected string $tag = 'section';
    protected string $cssClass = 'hero';
    protected bool $scopeCss = true;

    #[BlockField(type: 'string', validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'text')]
    public string $intro = '';

    #[BlockField(type: 'asset', label: 'Image', help: 'Optional.', config: ['asset' => ['multiple' => false, 'accept' => 'image/*']])]
    public string $image = '';

    #[BlockField(type: 'string', label: 'Button label')]
    public string $cta_label = '';

    #[BlockField(type: 'string', label: 'Button link', help: 'A path on this site, such as /jobs, or an https:// address.')]
    public string $cta_url = '';

    /** The button's href: cta_url when it is safe to link (safeLink()), else '' and no button. */
    public string $cta_href = '';

    public function resolveState(): void
    {
        $this->cta_href = self::safeLink($this->cta_url);
    }

    /**
     * $url when it is a path on this site or an http(s) address, else ''. Refused: other schemes
     * (javascript:), //host and /\host (a browser reads them as another site), and any whitespace
     * or control character (browsers drop tabs and newlines, so "/<tab>/host" becomes "//host").
     */
    public static function safeLink(string $url): string
    {
        return preg_match('/[\x00-\x20\x7f]/', $url) !== 1
            && preg_match('{^(/(?![/\\\\])|https?://)}', $url) === 1 ? $url : '';
    }
}
