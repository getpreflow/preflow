<?php

declare(strict_types=1);

namespace Preflow\View\Twig;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

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
        return $this->twig->render($template, $context);
    }

    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    /**
     * Get the underlying Twig environment for advanced use.
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
