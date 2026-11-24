<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

use Preflow\DevTools\Config\ConfigFileEditor;

/**
 * What every folio:install step works with: the app's folder, the config editor, the app's
 * console in a fresh process, whether this is a dry run, and the templates of the files it writes
 * (resources/install).
 *
 * @since 0.85.0
 */
final readonly class Setup
{
    public ConfigFileEditor $editor;

    public AppConsole $console;

    public function __construct(
        public string $root,
        public bool $dryRun,
    ) {
        $this->editor = new ConfigFileEditor($root . '/config');
        $this->console = new AppConsole($root);
    }

    public function exists(string $file): bool
    {
        return is_file($this->root . '/config/' . $file) || is_link($this->root . '/config/' . $file);
    }

    /**
     * A template of resources/install with its placeholders replaced: `'__NAME__'` (a quoted
     * string in the template) => the PHP literal to put there.
     *
     * @param array<string, string> $literals placeholder name => PHP code
     */
    public static function template(string $file, array $literals): string
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/install/' . $file);
        $replace = [];
        foreach ($literals as $name => $code) {
            $replace["'__{$name}__'"] = $code;
        }

        return strtr($text, $replace);
    }

    /** A PHP string literal. */
    public static function literal(string $value): string
    {
        return var_export($value, true);
    }
}
