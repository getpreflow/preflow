<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\SafeHtml;

final class SafeHtmlTest extends TestCase
{
    public function test_holds_its_html_and_casts_to_it(): void
    {
        $safe = new SafeHtml('<b>a</b>');

        $this->assertSame('<b>a</b>', $safe->html);
        $this->assertSame('<b>a</b>', (string) $safe);
    }
}
