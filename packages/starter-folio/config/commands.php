<?php

// App console commands, listed by class name. FolioCommands adds the folio:* commands;
// publish and publish:scheduled (cron) come with preflow/publishing.
return [
    ...\Preflow\Folio\Console\FolioCommands::all(),
    App\Console\EmployerCreateCommand::class,
];
