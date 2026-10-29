<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Rules\Step;
use Preflow\Validation\ValidationContext;

final class StepRuleTest extends TestCase
{
    private function check(int|float $step, mixed $value): true|Message
    {
        return (new Step($step))->validate($value, new ValidationContext('f', []));
    }

    public function test_accepts_multiples(): void
    {
        foreach ([2.5, '2.5', 0, -1.5, 10] as $value) {
            $this->assertTrue($this->check(0.5, $value), var_export($value, true));
        }
        $this->assertTrue($this->check(0.1, 0.3));
        $this->assertTrue($this->check(0.01, '19.99'));
    }

    public function test_refuses_off_step(): void
    {
        $result = $this->check(0.5, 2.3);

        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame('validation.step', $result->key);
        $this->assertSame(['step' => '0.5'], $result->params);
    }

    public function test_null_and_non_numeric_pass(): void
    {
        $this->assertTrue($this->check(0.5, null));
        $this->assertTrue($this->check(0.5, 'abc'));
    }

    public function test_non_positive_step_throws(): void
    {
        foreach ([0, -1, -0.5] as $step) {
            try {
                new Step($step);
                $this->fail("step {$step} should throw");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_resolves_alias(): void
    {
        $this->assertInstanceOf(Step::class, (new RuleFactory())->resolve('step:0.25'));
    }
}
