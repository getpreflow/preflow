<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\SharingTemplateEngine;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class SharingTemplateEngineTest extends TestCase
{
    private function recorder(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            /** @var list<string> */
            public array $calls = [];

            public function render(string $template, array $context = []): string
            {
                $this->calls[] = "render {$template}";

                return 'rendered ' . $template;
            }

            public function exists(string $template): bool
            {
                return $template === 'here.phtml';
            }

            public function addFunction(TemplateFunctionDefinition $function): void
            {
                $this->calls[] = "function {$function->name}";
            }

            public function addGlobal(string $name, mixed $value): void
            {
                $this->calls[] = "global {$name}";
            }

            public function getTemplateExtension(): string
            {
                return 'phtml';
            }

            public function addNamespace(string $namespace, string $path): void
            {
                $this->calls[] = "namespace {$namespace}";
            }
        };
    }

    public function test_it_is_the_app_engine_for_everything(): void
    {
        $app = $this->recorder();
        $engine = new SharingTemplateEngine($app);

        $this->assertSame('rendered x.phtml', $engine->render('x.phtml'));
        $this->assertTrue($engine->exists('here.phtml'));
        $this->assertSame('phtml', $engine->getTemplateExtension());
        $engine->addNamespace('ns', '/tmp');
        $this->assertSame(['render x.phtml', 'namespace ns'], $app->calls);
        $this->assertSame($app, $engine->engine());
    }

    public function test_a_sharing_engine_gets_every_function_and_global_before_and_after_but_no_namespace(): void
    {
        $app = $this->recorder();
        $engine = new SharingTemplateEngine($app);
        $engine->addFunction(new TemplateFunctionDefinition('csrf_field', static fn () => ''));
        $engine->addGlobal('site', 'x');
        $engine->addNamespace('app', '/tmp');

        $other = $this->recorder();
        $engine->share($other);
        $this->assertSame(['function csrf_field', 'global site'], $other->calls, 'what came before, in order');

        $engine->addFunction(new TemplateFunctionDefinition('folio_get', static fn () => null));
        $engine->addGlobal('later', 1);
        $engine->addNamespace('folio', '/tmp');

        $this->assertSame(['function csrf_field', 'global site', 'function folio_get', 'global later'], $other->calls);
        $this->assertSame(['function csrf_field', 'global site', 'namespace app', 'function folio_get', 'global later', 'namespace folio'], $app->calls);
    }

    /**
     * A sharer that can't take a late registration (a Twig engine that already rendered throws
     * LogicException) misses it; the app's engine still gets it, and nothing throws.
     */
    public function test_a_late_registration_a_sharer_refuses_still_reaches_the_app_engine(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sharing_log');
        $previous = ini_set('error_log', $log);
        try {
            $app = $this->recorder();
            $engine = new SharingTemplateEngine($app);
            $engine->share(new class implements TemplateEngineInterface {
                public function render(string $template, array $context = []): string { return ''; }
                public function exists(string $template): bool { return false; }
                public function addFunction(TemplateFunctionDefinition $function): void { throw new \LogicException('Unable to add function "' . $function->name . '" as extensions have already been initialized.'); }
                public function addGlobal(string $name, mixed $value): void { throw new \LogicException('Unable to add global "' . $name . '".'); }
                public function getTemplateExtension(): string { return 'twig'; }
                public function addNamespace(string $namespace, string $path): void {}
            });

            $engine->addFunction(new TemplateFunctionDefinition('late', static fn () => ''));
            $engine->addGlobal('later', 1);

            $this->assertSame(['function late', 'global later'], $app->calls);
            $this->assertStringContainsString('late', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($log);
        }
    }
}
