<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use Preflow\Testing\Contract\TemplateEngineContract;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;

/** The template engine contract on Twig. */
final class TwigContractTest extends TemplateEngineContract
{
    protected function createEngine(string $templateDir): TemplateEngineInterface
    {
        return new TwigEngine([$templateDir], new AssetCollector(new NonceGenerator(), isProd: false), debug: true);
    }

    protected function fixture(string $name): string
    {
        return match ($name) {
            'variable' => '{{ name }}',
            'escaped' => '{{ html }}',
            'raw_function', 'escaped_function' => "{{ shout('hi') }}",
            'global' => '{{ site }}',
            'namespaced' => "{% include '@lib/part.twig' %}",
            'part' => 'PART',
        };
    }
}
