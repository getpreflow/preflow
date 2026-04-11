<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class TwigEngine implements TemplateEngineInterface
{
    private readonly Environment $twig;

    /**
     * @param string[] $templateDirs Directories to search for templates
     */
    public function __construct(
        array $templateDirs,
        AssetCollector $assetCollector,
        bool $debug = false,
        ?string $cachePath = null,
    ) {
        $loader = new FilesystemLoader($templateDirs);

        $this->twig = new Environment($loader, [
            'debug' => $debug,
            'cache' => $cachePath ?: false,
            'auto_reload' => true,
            'strict_variables' => $debug,
            'autoescape' => 'html',
        ]);

        $this->twig->addExtension(new PreflowExtension($assetCollector));
    }

    public function render(string $template, array $context = []): string
    {
        // Absolute path (e.g., component templates) — load directly from filesystem
        if (str_starts_with($template, '/') && file_exists($template)) {
            $source = file_get_contents($template);
            $tpl = $this->twig->createTemplate($source, $template);
            return $tpl->render($context);
        }

        return $this->twig->render($template, $context);
    }

    public function exists(string $template): bool
    {
        if (str_starts_with($template, '/')) {
            return file_exists($template);
        }

        return $this->twig->getLoader()->exists($template);
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $options = $function->isSafe ? ['is_safe' => ['html']] : [];
        $this->twig->addFunction(new TwigFunction(
            $function->name,
            $function->callable,
            $options,
        ));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    public function getTemplateExtension(): string
    {
        return 'twig';
    }
}
