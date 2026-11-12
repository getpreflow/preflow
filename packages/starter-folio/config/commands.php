<?php

// App console commands, listed by class name. FolioCommands adds folio:publish,
// folio:publish-scheduled (cron) and the other folio:* commands.
return [
    ...\Preflow\Folio\Console\FolioCommands::all(),
    App\Console\EmployerCreateCommand::class,
];
