<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** How a text is marked up, which decides what a translation has to preserve. */
enum TextFormat: string
{
    case Plain = 'plain';
    case Html = 'html';
    case Markdown = 'markdown';
}
