<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use Exception;
use LimitIterator;
use SplFileObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class LogsCommand extends Command
{
    public const DEFAULT_LIMIT = 50;

    protected function configure(): void
    {
        $this->setName('system:logs')
            ->addOption(
                'filename',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Read contents of given file.',
                after(Config::get('logger.file.filename'), Config::get('tmpDir') . '/')
            )
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Show last X number messages.', self::DEFAULT_LIMIT)
            ->addOption('tail', 't', InputOption::VALUE_NONE, 'Tail logfile.')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear log file')
            ->setAliases(['logs'])
            ->setDescription('View current log file content.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('filename');
        $limit = (int)$input->getOption('limit');

        if (false === file_exists($file) && false === str_starts_with($file, '/')) {
            $file = Config::get('tmpDir') . '/' . $file;
        }

        if (empty($file) || false === file_exists($file) || false === is_file($file) || false === is_readable($file)) {
            if ($file === Config::get('logger.file.filename')) {
                $output->writeln('<info>Log file is empty. No records were found for today.</info>');
                return self::SUCCESS;
            }

            $output->writeln(sprintf('<error>Unable to read logfile \'%s\'.</error>', $file));
            return self::FAILURE;
        }

        $file = new SplFileObject($file, 'r');

        if ($input->getOption('clear')) {
            return $this->handleClearLog($file, $input, $output);
        }

        if ($input->getOption('tail')) {
            $p = $file->getRealPath();
            $lastPos = 0;

            while (true) {
                clearstatcache(false, $p);
                $len = filesize($p);
                if ($len < $lastPos) {
                    //-- file deleted or reset
                    $lastPos = $len;
                } elseif ($len > $lastPos) {
                    if (false === ($f = fopen($p, 'rb'))) {
                        $output->writeln(
                            sprintf('<error>Unable to open file \'%s\'.</error>', $file->getRealPath())
                        );
                        return self::FAILURE;
                    }

                    fseek($f, $lastPos);

                    while (!feof($f)) {
                        $buffer = fread($f, 4096);
                        $output->write((string)$buffer);
                        flush();
                    }

                    $lastPos = ftell($f);

                    fclose($f);
                }

                usleep(500000); //0.5s
            }
        }

        $limit = $limit < 1 ? self::DEFAULT_LIMIT : $limit;

        if ($file->getSize() < 1) {
            $output->writeln(
                sprintf('<comment>File \'%s\' is empty.</comment>', $file->getRealPath())
            );
            return self::SUCCESS;
        }

        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();

        $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);

        foreach ($it as $line) {
            $line = trim((string)$line);

            if (empty($line)) {
                continue;
            }

            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    private function handleClearLog(SplFileObject $file, InputInterface $input, OutputInterface $output): int
    {
        $logfile = after($file->getRealPath(), Config::get('tmpDir') . '/');

        if ($file->getSize() < 1) {
            $output->writeln(sprintf('<comment>Logfile [%s] is already empty.</comment>', $logfile));
            return self::SUCCESS;
        }

        if (false === $file->isWritable()) {
            $output->writeln(sprintf('<comment>Unable to write to logfile [%s].</comment>', $logfile));
            return self::FAILURE;
        }

        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty || $input->getOption('no-interaction')) {
            $output->writeln('<error>ERROR: This command flag require interaction.</error>');
            $output->writeln(
                '<comment>If you are running this tool inside docker, you have to enable interaction using "-ti" flag</comment>'
            );
            $output->writeln(
                '<comment>For example: docker exec -ti watchstate console servers:manage my_home_server</comment>'
            );
            return self::FAILURE;
        }

        $question = new ConfirmationQuestion(
            sprintf(
                'Clear file <info>[%s]</info> contents? <comment>%s</comment>' . PHP_EOL . '> ',
                after($file->getRealPath(), Config::get('tmpDir') . '/'),
                '[Y|N] [Default: No]',
            ),
            false
        );

        $confirmClear = $this->getHelper('question')->ask($input, $output, $question);

        if (true !== (bool)$confirmClear) {
            return self::SUCCESS;
        }

        $file->openFile('w');

        return self::SUCCESS;
    }
}