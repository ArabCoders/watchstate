<?php

declare(strict_types=1);

return [
    // config:
    'config:php' => App\Commands\Config\PHPCommand::class,
    'config:env' => App\Commands\Config\EnvCommand::class,
    'config:prune' => App\Commands\Config\PruneCommand::class,
    'config:logs' => \App\Commands\Config\LogsCommand::class,
    'logs' => \App\Commands\Config\LogsCommand::class,
    // -- state:
    'state:import' => App\Commands\State\ImportCommand::class,
    'state:export' => App\Commands\State\ExportCommand::class,
    'state:push' => App\Commands\State\PushCommand::class,
    // -- storage:
    'storage:maintenance' => App\Commands\Storage\MaintenanceCommand::class,
    'storage:migrations' => App\Commands\Storage\MigrationsCommand::class,
    'storage:make' => App\Commands\Storage\MakeCommand::class,
    // -- scheduler:
    'scheduler:list' => App\Commands\Scheduler\ListCommand::class,
    'scheduler:run' => App\Commands\Scheduler\RunCommand::class,
    'scheduler:closure' => App\Commands\Scheduler\RunClosureCommand::class,
    // -- server:
    'servers:list' => App\Commands\Servers\ListCommand::class,
    'servers:manage' => App\Commands\Servers\ManageCommand::class,
    'servers:unify' => App\Commands\Servers\UnifyCommand::class,
    'servers:view' => App\Commands\Servers\ViewCommand::class,
    'servers:edit' => App\Commands\Servers\EditCommand::class,
    // -- db:
    'db:list' => App\Commands\Database\ListCommand::class,
    'db:queue' => App\Commands\Database\QueueCommand::class,
    // -- backend:library:
    'backend:library:list' => App\Commands\Backend\Library\ListCommand::class,
    'backend:library:mismatch' => App\Commands\Backend\Library\MismatchCommand::class,
    'backend:library:unmatched' => App\Commands\Backend\Library\UnmatchedCommand::class,
    // -- backend:search:
    'backend:search:query' => App\Commands\Backend\Search\QueryCommand::class,
    'backend:search:id' => App\Commands\Backend\Search\IdCommand::class,
    // -- backend:users:
    'backend:users:list' => App\Commands\Backend\Users\ListCommand::class,
];
