<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

final class TwigEngineNamespaceTest extends TestCase
{
    public function test_addNamespace_resolves_templates(): void
    {
        $dir = sys_get_temp_dir() . '/folio_ns_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/hello.twig', 'NS:{{ name }}');

        $assets = new AssetCollector(new NonceGenerator(), isProd: false);
        $engine = new TwigEngine([sys_get_temp_dir()], $assets, debug: true);
        $engine->addNamespace('demo', $dir);

        $out = $engine->render('@demo/hello.twig', ['name' => 'Folio']);

        $this->assertSame('NS:Folio', $out);
    }

    public function test_addNamespace_first_path_wins(): void
    {
        $a = sys_get_temp_dir() . '/folio_ns_a_' . bin2hex(random_bytes(4));
        $b = sys_get_temp_dir() . '/folio_ns_b_' . bin2hex(random_bytes(4));
        mkdir($a, 0777, true);
        mkdir($b, 0777, true);
        file_put_contents($a . '/x.twig', 'A');
        file_put_contents($b . '/x.twig', 'B');

        $assets = new AssetCollector(new NonceGenerator(), isProd: false);
        $engine = new TwigEngine([sys_get_temp_dir()], $assets, debug: true);
        $engine->addNamespace('ns', $a); // registered first -> wins
        $engine->addNamespace('ns', $b);

        $this->assertSame('A', $engine->render('@ns/x.twig', []));
    }
}
