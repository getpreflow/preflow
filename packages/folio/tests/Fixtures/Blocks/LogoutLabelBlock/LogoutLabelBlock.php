<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\LogoutLabelBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;

/** A block whose label is a translation key (the preview marks' language, LinkedRecordPreviewTest, TypePreviewTest). */
#[Block(key: 'logout_label', label: 'folio.layout.log_out', group: 'Sections')]
final class LogoutLabelBlock extends BlockComponent {}
