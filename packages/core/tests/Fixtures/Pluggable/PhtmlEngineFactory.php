<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\Core\Http\NonPublicPaths;
use Preflow\View\TemplateEngineContext;
use Preflow\View\TemplateEngineFactory;
use Preflow\View\TemplateEngineInterface;

/** Named as app.engine; keeps what core passed, for the tests to read. */
final class PhtmlEngineFactory implements TemplateEngineFactory
{
    public static ?TemplateEngineContext $context = null;
    public static ?NonPublicPaths $paths = null;

    public function __construct(NonPublicPaths $paths)
    {
        self::$paths = $paths;
    }

    public static function templateExtension(): string
    {
        return 'phtml';
    }

    public function create(TemplateEngineContext $context): TemplateEngineInterface
    {
        self::$context = $context;

        return new PhtmlEngine($context->templateDirs);
    }
}
