<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\PhpEngine;

use Preflow\View\TemplateEngineContext;
use Preflow\View\TemplateEngineFactory;
use Preflow\View\TemplateEngineInterface;

/** Named as app.engine by the tests of Folio on an app whose engine is not Twig. */
final class PhtmlEngineFactory implements TemplateEngineFactory
{
    public static function templateExtension(): string
    {
        return 'phtml';
    }

    public function create(TemplateEngineContext $context): TemplateEngineInterface
    {
        return new PhtmlEngine($context->templateDirs);
    }
}
