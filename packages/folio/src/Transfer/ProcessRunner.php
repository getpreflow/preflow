<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/** proc_open with an argv array: no local shell, both pipes drained together. */
final class ProcessRunner implements CommandRunner
{
    public function run(array $argv, $stdout, $stderr): int
    {
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new TransferException('Could not start ' . $argv[0]);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $targets = [1 => $stdout, 2 => $stderr];

        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = $except = null;
            if (stream_select($read, $write, $except, 30) === false) {
                break;
            }
            foreach ($open as $fd => $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($targets[$fd], $chunk);
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$fd]);
                }
            }
        }

        return proc_close($proc);
    }
}
