<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class MaintenanceCommand extends Command
{
    public const ROUTE = 'system:db:maintenance';

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run maintenance tasks on database.')
            ->setHelp(
                <<<HELP

                This command runs <notice>maintenance</notice> tasks on database to make sure the database is in optimal state.
                You do not need to run this command unless told by the team.
                This is done automatically on container startup.

                HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $this->db->maintenance();

        return self::SUCCESS;
    }
}
