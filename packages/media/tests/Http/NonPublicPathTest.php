<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Media\Tests\Support\MediaApp;

/** Private files are not public pages: redirects and the not-found list leave them alone. */
final class NonPublicPathTest extends TestCase
{
    use MediaApp;

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    public function test_private_files_path_is_not_public(): void
    {
        $this->scaffoldMediaApp();
        $this->writeFile('config/media.php', "<?php\nreturn ['private_url' => '/tools/files'];\n");
        $paths = $this->mediaApp()->container()->get(NonPublicPaths::class);

        $this->assertTrue($paths->matches('/tools/files/abc123'));
        $this->assertFalse($paths->matches('/tools'));
        $this->assertFalse($paths->matches('/files/abc123'));
    }
}
