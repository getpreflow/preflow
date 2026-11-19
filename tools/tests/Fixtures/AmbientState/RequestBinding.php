<?php

// architecture.no-ambient-state fixture: one hit of the request-binding rule.
function ambientStateRequestBinding(\Preflow\Core\Container\Container $container, string $path): void
{
    $container->instance(\Preflow\Core\Http\RequestContext::class, new \Preflow\Core\Http\RequestContext($path, 'GET'));
}
