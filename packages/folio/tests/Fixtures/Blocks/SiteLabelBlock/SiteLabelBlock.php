<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\SiteLabelBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;

/** A block whose label is a translation key (the preview marks' language, LinkedRecordPreviewTest, TypePreviewTest). */
#[Block(key: 'site_label', label: 'site.blocks.teaser', group: 'Sections')]
final class SiteLabelBlock extends BlockComponent {}
