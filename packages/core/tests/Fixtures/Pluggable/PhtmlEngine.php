<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * A third template engine for the app tests: plain PHP templates (.phtml). A template sees its
 * context and the globals as variables, plus $e (escape), $call('function', ...$args) (printed:
 * escaped unless the function is safe) and $fn('function', ...$args) (its raw result).
 */
final class PhtmlEngine implements TemplateEngineInterface
{
    /** @var array<string, TemplateFunctionDefinition> */
    public array $functions = [];
    /** @var array<string, mixed> */
    private array $globals = [];
    /** @var array<string, list<string>> */
    private array $namespaces = [];

    /** @param list<string> $templateDirs */
    public function __construct(private readonly array $templateDirs) {}

    public function render(string $template, array $context = []): string
    {
        $file = $this->find($template) ?? throw new \RuntimeException("Template not found: {$template}");
        $vars = [...$this->globals, ...$context];
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $fn = function (string $name, mixed ...$args): mixed {
            $function = $this->functions[$name] ?? throw new \RuntimeException("Unknown template function: {$name}");

            return ($function->callable)(...$args);
        };
        $call = fn (string $name, mixed ...$args): string => ($this->functions[$name] ?? null)?->isSafe
            ? (string) $fn($name, ...$args)
            : $e($fn($name, ...$args));
        ob_start();
        try {
            (static function () use ($file, $vars, $e, $call, $fn): void {
                extract($vars, EXTR_SKIP);
                require $file;
            })();

            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    public function exists(string $template): bool
    {
        return $this->find($template) !== null;
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $this->functions[$function->name] = $function;
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    public function getTemplateExtension(): string
    {
        return 'phtml';
    }

    public function addNamespace(string $namespace, string $path): void
    {
        $this->namespaces[$namespace][] = rtrim($path, '/');
    }

    private function find(string $template): ?string
    {
        if (str_starts_with($template, '/')) {
            return is_file($template) ? $template : null; // a component's own template
        }
        $dirs = $this->templateDirs;
        if (preg_match('#^@([\w-]+)/(.+)$#', $template, $m) === 1) {
            [$dirs, $template] = [$this->namespaces[$m[1]] ?? [], $m[2]];
        }
        foreach ($dirs as $dir) {
            if (is_file($dir . '/' . $template)) {
                return $dir . '/' . $template;
            }
        }

        return null;
    }
}
