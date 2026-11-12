<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/** Binds an engine that is not Twig (as a Blade app's), recording the namespaces it gets and what it renders. */
final class NonTwigEngineProvider extends ServiceProvider
{
    public static ?TemplateEngineInterface $engine = null;

    public function register(Container $container): void
    {
        self::$engine = new class implements TemplateEngineInterface {
            /** @var list<string> */
            public array $namespaces = [];

            /** @var list<string> */
            public array $rendered = [];

            public function render(string $template, array $context = []): string
            {
                $this->rendered[] = $template;

                return '<p>site layout</p>';
            }

            public function exists(string $template): bool
            {
                return false;
            }

            public function addFunction(TemplateFunctionDefinition $function): void {}

            public function addGlobal(string $name, mixed $value): void {}

            public function getTemplateExtension(): string
            {
                return 'blade.php';
            }

            public function addNamespace(string $namespace, string $path): void
            {
                $this->namespaces[] = $namespace;
            }
        };
        $container->instance(TemplateEngineInterface::class, self::$engine);
    }
}
