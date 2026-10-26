<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Support;

/** A temp directory per test with helpers for {dir}/{locale}/{group}.php files and other files. */
trait TempLang
{
    private string $tmp = '';

    private function tmp(): string
    {
        if ($this->tmp === '') {
            $this->tmp = sys_get_temp_dir() . '/preflow_i18n_' . bin2hex(random_bytes(5));
            mkdir($this->tmp, 0777, true);
        }

        return $this->tmp;
    }

    /** @param array<string, mixed> $data */
    private function langFile(string $dir, string $locale, string $group, array $data): void
    {
        $this->file($dir . '/' . $locale . '/' . $group . '.php', '<?php return ' . var_export($data, true) . ';');
    }

    private function file(string $relative, string $content): string
    {
        $path = $this->tmp() . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    private function removeTmp(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmp);
        $this->tmp = '';
    }
}
