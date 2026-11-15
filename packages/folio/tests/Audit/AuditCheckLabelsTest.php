<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\BuiltInChecks;

/**
 * Every finding id the built-in audit checks report (the quoted dotted ids in their sources, such as
 * "geo.type-schema-missing") has its name in Folio's audit screen in en, de and fr
 * (folio.audit.checks.{id with "_" for "."}), so none shows as its raw id.
 */
final class AuditCheckLabelsTest extends TestCase
{
    public function test_every_built_in_check_id_has_a_label_in_each_language(): void
    {
        $dir = dirname((string) (new \ReflectionClass(BuiltInChecks::class))->getFileName());
        $ids = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            preg_match_all("/'([a-z0-9-]+(?:\.[a-z0-9-]+)+)'/", (string) file_get_contents($file), $m);
            $ids = [...$ids, ...$m[1]];
        }
        $ids = array_values(array_unique($ids));
        $this->assertContains('geo.type-schema-missing', $ids);
        $this->assertContains('geo.markdown-thin', $ids);
        $this->assertContains('llms.full-too-large', $ids);

        foreach (['en', 'de', 'fr'] as $locale) {
            $checks = (require dirname(__DIR__, 2) . '/lang/' . $locale . '/folio.php')['audit']['checks'];
            $missing = array_values(array_filter($ids, static fn (string $id): bool => !is_string($checks[str_replace('.', '_', $id)] ?? null)));
            $this->assertSame([], $missing, $locale);
        }
    }
}
