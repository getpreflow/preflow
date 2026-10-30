<?php

declare(strict_types=1);

namespace Preflow\Ai\Console;

use Preflow\Ai\AiCheck;
use Preflow\Ai\AiCheckResult;
use Preflow\DevTools\Command\CommandInterface;

/**
 * Shows the AI status and sends one tiny request (asking for {"ok":true}), to verify key, model
 * and network on a live server. Exit 0 only when the answer came back as asked; 1 when AI is
 * off or the request failed. Never prints the key.
 */
final class AiCheckCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'ai:check'; }
    public function getDescription(): string { return 'Show the AI status and send one tiny test request'; }

    public function execute(array $args): int
    {
        $ai = $this->ai($this->app());
        if ($ai === null) {
            return 1;
        }

        $status = $ai->status();
        echo 'Provider: ' . $status->provider . "\n";
        echo 'Model:    ' . $status->model . "\n";
        echo 'Key:      ' . ($status->keyPresent ? 'present' : 'missing') . "\n";
        echo 'Enabled:  ' . ($status->enabled ? 'yes' : 'no') . "\n";
        if (!$status->enabled) {
            $this->error("AI is off: set \"enabled\" => true in config/ai.php and the provider's key in .env.\n");

            return 1;
        }

        $result = (new AiCheck($ai))->run();
        if ($result->state === AiCheckResult::FAILED) {
            $this->error('The test request failed: ' . $result->message . "\n");

            return 1;
        }
        if ($result->usage !== null) {
            echo sprintf(
                "Tokens:   %d in, %d out, cost %s\n",
                $result->usage->input,
                $result->usage->output,
                $result->cost === null ? 'unknown (no price for this model)' : '$' . number_format($result->cost, 6),
            );
        }
        if (!$result->ok()) {
            $this->error("The model answered, but not with {\"ok\":true}.\n");

            return 1;
        }
        echo "OK\n";

        return 0;
    }
}
