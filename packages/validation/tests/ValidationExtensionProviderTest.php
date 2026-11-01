<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\OldInput;
use Preflow\Testing\ArraySession;
use Preflow\Validation\ValidationExtensionProvider;

final class ValidationExtensionProviderTest extends TestCase
{
    public function test_old_falls_back_to_flashed_input(): void
    {
        $session = new ArraySession();
        $session->start();
        OldInput::keep($session, ['name' => 'Ada', 'city' => 'Bern']);
        $session->ageFlash();

        $provider = new ValidationExtensionProvider(new OldInput(static fn () => $session));
        $provider->setOldInput(['name' => 'Grace']);

        $this->assertSame('Grace', $this->old($provider, 'name'));
        $this->assertSame('Bern', $this->old($provider, 'city'));
        $this->assertSame('-', $this->old($provider, 'email', '-'));
        // Standalone, without core's OldInput.
        $this->assertSame('-', $this->old(new ValidationExtensionProvider(), 'city', '-'));
    }

    private function old(ValidationExtensionProvider $provider, string $field, mixed $default = null): mixed
    {
        foreach ($provider->getTemplateFunctions() as $function) {
            if ($function->name === 'old') {
                return ($function->callable)($field, $default);
            }
        }
        $this->fail('old() is not registered');
    }
}
