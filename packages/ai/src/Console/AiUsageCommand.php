<?php

declare(strict_types=1);

namespace Preflow\Ai\Console;

use Preflow\Ai\Usage\UsageLog;
use Preflow\DevTools\Command\CommandInterface;

/** Calls, tokens and cost of a month (default: this one), per purpose and model. */
final class AiUsageCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'ai:usage'; }
    public function getDescription(): string { return 'Show AI calls, tokens and cost per purpose and model [--month=YYYY-MM]'; }

    public function execute(array $args): int
    {
        $month = date('Y-m');
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--month=')) {
                $month = substr($arg, 8);
            }
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $this->error("Usage: preflow ai:usage [--month=YYYY-MM]\n");

            return 1;
        }

        $container = $this->app()->container();
        if (!$container->bound(UsageLog::class)) {
            $this->error("preflow/ai is not set up: add Preflow\\Ai\\AiServiceProvider to config/providers.php.\n");

            return 1;
        }

        $from = new \DateTimeImmutable($month . '-01 00:00:00');
        $entries = $container->get(UsageLog::class)->between($from, $from->modify('last day of this month')->setTime(23, 59, 59));
        if ($entries === []) {
            echo "No AI calls in {$month}.\n";

            return 0;
        }

        /** @var array<string, array{purpose: string, model: string, calls: int, failed: int, input: int, output: int, cost: float, unknown: bool}> $rows */
        $rows = [];
        foreach ($entries as $e) {
            $row = &$rows[$e->purpose . "\0" . $e->model];
            $row ??= ['purpose' => $e->purpose, 'model' => $e->model, 'calls' => 0, 'failed' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0, 'unknown' => false];
            $row['calls']++;
            $row['failed'] += $e->failed ? 1 : 0;
            $row['input'] += $e->usage->input;
            $row['output'] += $e->usage->output;
            $row['cost'] += $e->costUsd ?? 0.0;
            $row['unknown'] = $row['unknown'] || $e->costUsd === null;
            unset($row);
        }
        ksort($rows);

        echo "AI usage {$month}\n";
        printf("%-24s %-22s %6s %7s %10s %10s %12s\n", 'Purpose', 'Model', 'Calls', 'Failed', 'Input', 'Output', 'Cost (USD)');
        $total = 0.0;
        $unknown = false;
        foreach ($rows as $r) {
            $total += $r['cost'];
            $unknown = $unknown || $r['unknown'];
            printf("%-24s %-22s %6d %7d %10d %10d %12s\n", $r['purpose'], $r['model'], $r['calls'], $r['failed'], $r['input'], $r['output'], self::money($r['cost'], $r['unknown']));
        }
        printf("%-24s %-22s %6d %7d %10d %10d %12s\n", 'Total', '', array_sum(array_column($rows, 'calls')), array_sum(array_column($rows, 'failed')), array_sum(array_column($rows, 'input')), array_sum(array_column($rows, 'output')), self::money($total, $unknown));

        return 0;
    }

    /** A "+" marks a sum that leaves out calls of models without a price. */
    private static function money(float $usd, bool $incomplete): string
    {
        return number_format($usd, 4) . ($incomplete ? '+' : '');
    }
}
