<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Override;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Override\OverridableAction;

final class ActionResolverTest extends TestCase
{
    public function test_resolves_existing_override(): void
    {
        $resolver = new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Fixtures\\Overrides\\');
        $action = $resolver->resolve('Content', 'Index');

        $this->assertInstanceOf(OverridableAction::class, $action);

        $request = (new Psr17Factory())->createServerRequest('GET', '/folio');
        $this->assertSame('OVERRIDDEN', (string) $action->handle($request)->getBody());
    }

    public function test_returns_null_when_no_override(): void
    {
        $resolver = new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Fixtures\\Overrides\\');
        $this->assertNull($resolver->resolve('Content', 'Nonexistent'));
    }
}
