<?php

// Child script for SessionDataGuardTest: a visitor's session is hidden for a
// render that never returns (exit stands in for a timeout: finally blocks
// don't run, shutdown functions do). The session file must keep the data.

declare(strict_types=1);

require $argv[1];

session_save_path($argv[2]);
session_id($argv[3]);
session_start();
$_SESSION['_auth_user_id'] = 'member';

$guard = new \Preflow\Core\Http\Session\SessionDataGuard();
$guard->hide();
try {
    exit(0);
} finally {
    $guard->restore(); // never reached
}
