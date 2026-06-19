<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

final class TemplatesExistTest extends TestCase
{
    public function test_shipped_templates_present(): void
    {
        $base = dirname(__DIR__) . '/templates';
        foreach ([
            '/admin/_layout.twig',
            '/admin/dashboard.twig',
            '/admin/list.twig',
            '/admin/form.twig',
            '/admin/login.twig',
            '/frontend/page.twig',
        ] as $rel) {
            $this->assertFileExists($base . $rel);
        }
    }
}
