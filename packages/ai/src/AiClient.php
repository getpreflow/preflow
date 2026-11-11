<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * One completion call, whatever the provider. Callers depend on this interface so a
 * project can switch between Claude, Gemini, the fake or a provider of its own (an
 * AiClientFactory) by config.
 */
interface AiClient
{
    /**
     * @throws AiUnavailable   the provider cannot be used at all: stop the whole run
     * @throws AiRequestFailed this request failed: others may still succeed
     */
    public function complete(AiRequest $request): AiResponse;

    /** 'claude' | 'gemini' | 'fake' | your provider's name: the usage log records it */
    public function provider(): string;

    public function model(): string;
}
