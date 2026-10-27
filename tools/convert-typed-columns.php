<?php

declare(strict_types=1);

/*
 * One-time conversion of a site's record tables to typed columns (Preflow data 0.42).
 * Not part of any package: run it from this checkout against a site directory.
 *
 *   php tools/convert-typed-columns.php <site-dir> [--check] [--storage=<driver>] [--sqlite=<file>] [--backup-dir=<dir>]
 *
 * check → backup (<site-dir>/storage/backups/) → convert table by table → re-check. Any value that
 * does not fit (a number field holding text, a yes/no other than '', 0, 1, true, false, a date not
 * in a stored format, a short text over its length, a duplicate in a unique field) is listed and
 * nothing is changed. --check only reports. A rerun continues where an interrupted run stopped.
 * --sqlite=<file> runs against that SQLite file (a copy) instead of the configured one; its backup
 * then goes to backups/ beside that file, not into the site (unless --backup-dir says otherwise).
 * Exit code: 0 converted, clean or nothing to do; 1 failures, a failed backup or an error.
 * See packages/data/README.md, "Converting record tables".
 */

require __DIR__ . '/Convert/autoload.php';

exit((new Preflow\Tools\Convert\Cli())->run(array_slice($argv, 1)));
