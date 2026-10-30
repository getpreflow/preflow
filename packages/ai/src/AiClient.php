<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * One completion call, whatever the provider. Callers depend on this interface so a
 * project can switch between Claude and Gemini (or the fake in tests) by config.
 */
interface AiClient
{
    /**
     * @throws AiUnavailable   the provider cannot be used at all: stop the whole run
     * @throws AiRequestFailed this request failed: others may still succeed
     */
    public function complete(AiRequest $request): AiResponse;

    /** 'claude' | 'gemini' | 'fake' */
    public function provider(): string;

    public function model(): string;
}
