<?php
/**
 * SugarCLI
 *
 * PHP Version 5.3 -> 5.4
 * SugarCRM Versions 6.5 - 7.6
 *
 * @author Rémi Sauvat
 * @author Emmanuel Dyan
 * @copyright 2005-2015 iNet Process
 *
 * @package inetprocess/sugarcrm
 *
 * @license Apache License 2.0
 *
 * @link http://www.inetprocess.com
 */

namespace SugarCli\Console\Command\Anonymize;

use SugarCli\Console\Command\AbstractConfigOptionCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class AnonymizeRunCommand extends AbstractConfigOptionCommand
{

    protected function configure()
    {
        $this->setName('anonymize:run')
            ->setDescription('Run the Anonymizer')
            ->addConfigOptionMapping('path', 'sugarcrm.path')
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the configuration file',
                '../db/anonymization.yml'
            )->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Run the queries"
            )->addOption(
                'remove-deleted',
                null,
                InputOption::VALUE_NONE,
                "Remove all records with deleted = 1. Won't be launched if --force is not set"
            )->addOption(
                'clean-cstm',
                null,
                InputOption::VALUE_NONE,
                "Clean all records in _cstm that are not in the main table. Won't be launched if --force is not set"
            )->addOption(
                'sql',
                null,
                InputOption::VALUE_NONE,
                'Display the SQL of UPDATE queries'
            )->addOption(
                'table',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Anonymize only that table (repeat for multiple values)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stopwatch = new \Symfony\Component\Stopwatch\Stopwatch();
        $stopwatch->start('Anon');

        $this->setSugarPath($this->getConfigOption($input, 'path'));
        $pdo = $this->getService('sugarcrm.pdo');
        $ep = $this->getService('sugarcrm.entrypoint'); // go to sugar folder to make sure we are in the right folder

        // Make sure that we don't anonymize production
        $pretend = $input->getOption('force') === true ? false : true;
        if ($pretend === false) {
            $output->writeln("<error>Be careful, the anonymization is going to start</error>");
            $output->writeln("<error>That will overwrite every data in the Database !</error>" . PHP_EOL);
            $helper = $this->getHelper('question');
            $question = new Question('If you are sure, please type "yes" in uppercase' . PHP_EOL);
            $confirmation = $helper->ask($input, $output, $question);
            if ($confirmation !== 'YES') {
                $output->writeln("Bye !");
                return;
            }
        }

        // Anon READER
        $reader = new \Inet\Neuralyzer\Configuration\Reader($input->getOption('file'));

        // Now work on the DB
        $anon = new \Inet\Neuralyzer\Anonymizer\DB($pdo);
        $anon->setConfiguration($reader);

        // Get tables
        $tables = $input->getOption('table');
        if (empty($tables)) {
            $tables = $reader->getEntities();
        }

        foreach ($tables as $table) {
            // removing deleted from the tables which have that field
            if ($input->getOption('remove-deleted') === true && $pretend === false) {
                $deletedField = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'deleted'")->fetchColumn();
                if (!empty($deletedField)) {
                    $del = $pdo->query("DELETE FROM $table WHERE deleted = 1");
                    if ($del === false) {
                        throw new \PDOException("Can't run the query to delete records from $table");
                    }
                    $output->writeln("<info>Removed " . $del->rowCount() . " deleted records from $table</info>");
                }
            }

            // Clean custom table if asked, with id not in the main table
            if ($input->getOption('clean-cstm') === true && substr($table, -5) === '_cstm' && $pretend === false) {
                $del = $pdo->query(
                    "DELETE FROM $table WHERE id_c NOT IN (SELECT id FROM `" . substr($table, 0, -5) . "`)"
                );
                if ($del === false) {
                    throw new \PDOException("Can't run the query to delete records from $table");
                }
                $output->writeln("<info>Removed " . $del->rowCount() . " useless records from $table</info>");
            }


            $result = $pdo->query("SELECT COUNT(1) FROM $table");
            $data = $result->fetchAll(\PDO::FETCH_COLUMN);
            $total = (int)$data[0];
            if ($total === 0) {
                $output->writeln("<info>$table is empty</info>" . PHP_EOL);
                continue;
            }

            $bar = new ProgressBar($output, $total);
            $output->writeln("<info>Anonymizing $table</info>");
            $queries = $anon->processEntity($table, function () use ($bar) {
                $bar->advance();
            }, $pretend, $input->getOption('sql'));

            $output->writeln(PHP_EOL);

            if ($input->getOption('sql')) {
                $output->writeln('<comment>Queries:</comment>');
                $output->writeln(implode(PHP_EOL, $queries));
                $output->writeln(PHP_EOL);
            }
        }

        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $data = $pdo->query($sql = "SHOW TABLES WHERE `tables_in_{$db}`
                                    LIKE '%_audit' OR `tables_in_{$db}` LIKE '%_cache'
                                    OR `tables_in_{$db}` LIKE 'tracker'
                                    OR `tables_in_{$db}` LIKE 'tracker_%'");
        if ($data === false) {
            throw new \PDOException("Can't run the query to empty audit and trackers: " . PHP_EOL . $sql);
        }
        foreach ($data as $row) {
            $table = $row[0];
            $output->writeln("<info>Emptying $table</info>");
            $pdo->query("TRUNCATE TABLE `$table`");
        }


        // Get memory and execution time information
        $event = $stopwatch->stop('Anon');
        $memory = round($event->getMemory() / 1024 / 1024, 2);
        $time = round($event->getDuration() / 1000, 2);
        $time = ($time > 180 ? round($time / 60, 2) . 'mins' : "$time sec");

        // Final message
        $output->writeln(PHP_EOL . "<comment>Done in $time (consuming {$memory}Mb)</comment>");
        if ($pretend === false) {
            $output->writeln(PHP_EOL . "<comment>To export the db run: </comment>");
            $output->writeln(" mysqldump --skip-lock-tables $db | bzip2 > $db." . date('Ymd-Hi') . ".sql.bz2");
        } else {
            $output->writeln(PHP_EOL . "<error>The anonymization didn't run. Use --force to run it.</error>");
        }
    }
}
