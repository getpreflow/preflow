<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use Preflow\Blade\BladeEngine;
use Preflow\Testing\Contract\TemplateEngineContract;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;

/** The template engine contract on Blade. */
final class BladeContractTest extends TemplateEngineContract
{
    protected function createEngine(string $templateDir): TemplateEngineInterface
    {
        // Compiled views go next to the templates, so the contract's clean-up removes them too.
        return new BladeEngine(
            templateDirs: [$templateDir],
            assetCollector: new AssetCollector(new NonceGenerator()),
            cachePath: $templateDir . '/.compiled',
        );
    }

    protected function fixture(string $name): string
    {
        return match ($name) {
            'variable' => '{{ $name }}',
            'escaped' => '{{ $html }}',
            'raw_function', 'escaped_function' => "@shout('hi')",
            'global' => '{{ $site }}',
            'namespaced' => "@include('lib::part')",
            'part' => 'PART',
        };
    }
}
