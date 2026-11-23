<?php

declare(strict_types=1);

namespace Preflow\Tools\Phpstan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A function that only exists on 8.4 because a polyfill defines it is not a function of 8.4.
 *
 * PHPStan reads the functions of every Composer autoload file, so with symfony/polyfill-php85
 * installed (illuminate/support requires it) array_first() looks available, even with
 * phpVersion 80400. The packages do not declare the polyfill, so a site without Illuminate
 * would fail at runtime on 8.4. This rule flags a call to any function that a polyfill of a
 * newer PHP than the floor defines.
 *
 * @implements Rule<FuncCall>
 */
final class NoPolyfilledFunctionsRule implements Rule
{
    /** Polyfills of PHP versions above the 8.4 floor. */
    private const POLYFILLS = '#/symfony/polyfill-php8[5-9]/#';

    public function __construct(private readonly ReflectionProvider $reflection)
    {
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || !$this->reflection->hasFunction($node->name, $scope)) {
            return [];
        }

        $function = $this->reflection->getFunction($node->name, $scope);
        $file = $function->getFileName();
        if ($file === null || preg_match(self::POLYFILLS, str_replace('\\', '/', $file)) !== 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Function %s() needs a newer PHP than 8.4; only a polyfill defines it here.',
                $function->getName(),
            ))->identifier('preflow.polyfilledFunction')->build(),
        ];
    }
}
