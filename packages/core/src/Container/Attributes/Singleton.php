<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Attributes;

/**
 * Marks a class the container shares when it autowires it: the first get()
 * builds it, every later get() returns that instance, whichever code asks
 * first. A binding for the class (bind()/singleton()) takes precedence.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Singleton
{
}
