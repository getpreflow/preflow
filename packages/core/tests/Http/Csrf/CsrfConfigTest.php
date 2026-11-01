<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Csrf\CsrfConfig;
use Preflow\Core\Http\Csrf\CsrfSignInPaths;

final class CsrfConfigTest extends TestCase
{
    public function test_missing_file_means_the_defaults(): void
    {
        $config = CsrfConfig::fromFile(sys_get_temp_dir() . '/no-such-csrf-config-' . bin2hex(random_bytes(4)) . '.php');

        $this->assertSame(['/--component/'], $config->exempt);
        $this->assertSame([], $config->trustedOrigins);
        $this->assertSame([], $config->signInPaths);
    }

    public function test_reads_every_key(): void
    {
        $file = sys_get_temp_dir() . '/csrf_config_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\nreturn ['exempt' => ['/--component/', '/hooks/'], 'trusted_origins' => ['https://stage.example.com'], 'sign_in_paths' => ['/login']];\n");

        try {
            $config = CsrfConfig::fromFile($file);
        } finally {
            unlink($file);
        }

        $this->assertSame(['/--component/', '/hooks/'], $config->exempt);
        $this->assertSame(['https://stage.example.com'], $config->trustedOrigins);
        $this->assertSame(['/login'], $config->signInPaths);
    }

    public function test_exempt_adds_to_the_component_prefix(): void
    {
        $file = sys_get_temp_dir() . '/csrf_config_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\nreturn ['exempt' => ['/hooks/']];\n");

        try {
            $config = CsrfConfig::fromFile($file);
        } finally {
            unlink($file);
        }

        $this->assertSame(['/--component/', '/hooks/'], $config->exempt);
    }

    public function test_sign_in_paths_match_exactly_ignoring_a_trailing_slash(): void
    {
        $paths = new CsrfSignInPaths();
        $paths->add('/members/login/');
        $paths->add('/');

        $this->assertTrue($paths->matches('/members/login'));
        $this->assertTrue($paths->matches('/members/login/'));
        $this->assertTrue($paths->matches('/'));
        $this->assertFalse($paths->matches('/members/login/extra'));
        $this->assertFalse($paths->matches('/members'));
        $this->assertFalse((new CsrfSignInPaths())->matches('/members/login'));
    }
}
