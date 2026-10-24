<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Folio\Extension\ExtensionDefinition;
use Preflow\Folio\Extension\ExtensionRegistry;

/**
 * A temp copy of the Shop fixture's assets/ as the enabled extension "shop", plus what must
 * never be served: secret.css beside assets/ (outside it), a .php and a .twig file inside it,
 * symlinks inside it pointing outside (file and folder) and at the .php file, and a disabled
 * extension "off" with its own assets/off.css.
 */
trait ExtensionAssetsSite
{
    private string $site;

    private function assetsSite(): ExtensionRegistry
    {
        $this->site = sys_get_temp_dir() . '/folio_xassets_' . bin2hex(random_bytes(4));
        $shop = $this->site . '/shop';
        $source = dirname(__DIR__) . '/Fixtures/extensions/Shop/assets';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $this->siteWrite('shop/assets/' . substr($file->getPathname(), strlen($source) + 1), (string) file_get_contents($file->getPathname()));
        }
        $this->siteWrite('shop/secret.css', 'TOP-SECRET outside assets');
        $this->siteWrite('shop/assets/server.php', '<?php echo "TOP-SECRET php";');
        $this->siteWrite('shop/assets/view.twig', 'TOP-SECRET twig');
        $this->siteWrite('outside/leak.css', 'TOP-SECRET via symlink');
        symlink($this->site . '/outside/leak.css', $shop . '/assets/leak.css');
        symlink($this->site . '/outside', $shop . '/assets/linked');
        symlink($shop . '/assets/server.php', $shop . '/assets/alias.js');
        $this->siteWrite('off/assets/off.css', '.off {}');

        return new ExtensionRegistry([
            self::definition('shop', $shop),
            self::definition('off', $this->site . '/off'),
        ], ['shop' => []]);
    }

    private function siteWrite(string $rel, string $content): void
    {
        $path = $this->site . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function removeAssetsSite(): void
    {
        if (!isset($this->site) || !is_dir($this->site)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->site, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            ($f->isDir() && !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->site);
    }

    private static function definition(string $name, string $folder): ExtensionDefinition
    {
        return new ExtensionDefinition($name, ucfirst($name), '', '', '1.0.0', 'Acme\\' . ucfirst($name) . '\\Extension', $folder, []);
    }

    /**
     * Requests that must 404 (path as it arrives in the route attribute: not URL-decoded).
     *
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        return [
            'parent dir' => ['shop', '../secret.css'],
            'nested parent dir' => ['shop', 'vendor/../../secret.css'],
            'parent dir inside assets' => ['shop', 'vendor/../shop.css'],
            'dot segment' => ['shop', './shop.css'],
            'encoded traversal' => ['shop', '%2e%2e%2fsecret.css'],
            'half-encoded traversal' => ['shop', '..%2fsecret.css'],
            'encoded name' => ['shop', 'shop%2ecss'],
            'absolute path' => ['shop', '/etc/hosts'],
            'absolute path to an asset' => ['shop', dirname(__DIR__) . '/Fixtures/extensions/Shop/assets/shop.css'],
            'backslash' => ['shop', 'vendor\\lib\\lib.js'],
            'backslash traversal' => ['shop', '..\\secret.css'],
            'NUL byte' => ['shop', "shop.css\0.png"],
            'empty segment' => ['shop', 'vendor//lib/lib.js'],
            'empty path' => ['shop', ''],
            'folder' => ['shop', 'vendor/lib'],
            'hidden file' => ['shop', '.htaccess'],
            'missing file' => ['shop', 'nope.css'],
            'php file' => ['shop', 'server.php'],
            'twig file' => ['shop', 'view.twig'],
            'upper-case type' => ['shop', 'SHOP.CSS'],
            'symlink to a file outside' => ['shop', 'leak.css'],
            'symlinked folder outside' => ['shop', 'linked/leak.css'],
            'symlink to a php file' => ['shop', 'alias.js'],
            'disabled extension' => ['off', 'off.css'],
            'unknown extension' => ['nope', 'shop.css'],
            'extension name traversal' => ['../app', 'shop.css'],
            'extension name with dots' => ['..', 'shop/assets/shop.css'],
            'empty extension name' => ['', 'shop.css'],
        ];
    }
}
