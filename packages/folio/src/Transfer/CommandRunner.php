<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/** Runs a local program (ssh for folio:pull); a fake replaces it in tests. */
interface CommandRunner
{
    /**
     * @param list<string> $argv program and arguments (no shell)
     * @param resource $stdout receives the child's stdout
     * @param resource $stderr receives the child's stderr
     * @return int the exit code
     */
    public function run(array $argv, $stdout, $stderr): int;
}
