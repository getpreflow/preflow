<?php

// Child script for AnonymousGlobalsAdapterTest: a visitor's session is hidden for an internal
// render that never returns (exit stands in for a timeout: finally blocks and the frame's leave
// hooks don't run, shutdown functions do). The session file must keep the data.

declare(strict_types=1);

require $argv[1];

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\PageCache\AnonymousGlobals;

session_save_path($argv[2]);
session_id($argv[3]);
session_start();
$_SESSION['_auth_user_id'] = 'member';

Contexts::onEnter(AnonymousGlobals::adapter(), AnonymousGlobals::HOOK);
Contexts::run(RenderContext::internal(Purpose::Audit, new Site('https://site.test', ['en'], 'en'), new SystemClock(), '/'), static function (): void {
    if (isset($_SESSION)) {
        echo 'the session was not hidden';
        exit(1);
    }
    exit(0);
});
