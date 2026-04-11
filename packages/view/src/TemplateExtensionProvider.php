<?php

declare(strict_types=1);

namespace Preflow\View;

interface TemplateExtensionProvider
{
    /** @return TemplateFunctionDefinition[] */
    public function getTemplateFunctions(): array;

    /** @return array<string, mixed> */
    public function getTemplateGlobals(): array;
}
