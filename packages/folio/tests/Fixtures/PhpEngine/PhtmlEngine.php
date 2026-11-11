<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\PhpEngine;

use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * An app engine that is not Twig: plain PHP templates (.phtml) from the template dirs, with $e
 * (escape) and $call('function', ...$args) (printed: escaped unless the function is safe).
 */
final class PhtmlEngine implements TemplateEngineInterface
{
    /** @var array<string, TemplateFunctionDefinition> */
    public array $functions = [];
    /** @var array<string, mixed> */
    private array $globals = [];

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
        $call = fn (string $name, mixed ...$args): string => ($this->functions[$name] ?? null)?->isSafe ? (string) $fn($name, ...$args) : $e($fn($name, ...$args));
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
        throw new \LogicException("The app's engine got Folio's namespace @{$namespace}: Folio's Twig templates are its own engine's.");
    }

    private function find(string $template): ?string
    {
        if (str_contains($template, '..') || str_starts_with($template, '@')) {
            return null;
        }
        if (str_starts_with($template, '/')) {
            return is_file($template) ? $template : null;
        }
        foreach ($this->templateDirs as $dir) {
            if (is_file("{$dir}/{$template}")) {
                return "{$dir}/{$template}";
            }
        }

        return null;
    }
}
