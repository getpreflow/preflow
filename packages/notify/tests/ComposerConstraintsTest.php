<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;

/**
 * notify has no hard dependency on another package: the template layout needs preflow/view, which is
 * only suggested. Core boots notify from config/notify.php; a core before 0.68 builds no Mailer, no
 * mail layout and no @notify templates, which the packages sending composed mails need, so it conflicts.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_only_php_and_conflicts_with_a_core_before_the_mail_layer(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['php' => '>=8.4'], $composer['require'] ?? null);
        $this->assertSame(['preflow/core' => '<0.68'], $composer['conflict'] ?? null);
        $this->assertArrayHasKey('preflow/view', $composer['suggest'] ?? []);
    }
}
