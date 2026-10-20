<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

/** config/image.php holds the responsive image presets (image.presets). */
final class ImageConfigTest extends TestCase
{
    public function test_image_config_file_is_loaded(): void
    {
        $dir = sys_get_temp_dir() . '/imgcfg_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/image.php', "<?php\nreturn ['presets' => ['hero' => ['widths' => [640, 1280]]]];\n");

        try {
            $app = Application::create($dir);
            $this->assertSame([640, 1280], $app->config()->get('image.presets.hero.widths'));
        } finally {
            @unlink($dir . '/config/image.php');
            @unlink($dir . '/config/app.php');
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
    }
}
