<?php

namespace SugarCli\Console\Command\Backup;

use SugarCli\Console\Command\AbstractConfigOptionCommand;
use SugarCli\Console\ExitCode;
use SugarCli\Console\Command\Backup\Common;
use SugarCli\Utils\Utils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\ProcessUtils;

class DumpDatabaseCommand extends AbstractConfigOptionCommand
{
    protected static $compression_formats = array(
        'gzip' => '.gz',
        'bzip2' => '.bz2',
    );

    protected static $dev_ignored_tables = array(
        'activities',
        'activities_users',
        'fts_queue',
        'inbound_email',
        'job_queue',
        'outbound_email',
        'tracker',
        'tracker_perf',
        'tracker_queries',
        'tracker_sessions',
        'tracker_tracker_queries',
    );

    protected $temp_file;

    protected $datetime;

    public function getDateTime()
    {
        if ($this->datetime == null) {
            $this->datetime = new \DateTime();
        }
        return $this->datetime;
    }

    public function setDateTime(\DateTime $datetime)
    {
        $this->datetime = $datetime;
    }

    protected function configure()
    {
        Common::addCommonDumpOptions($this, self::$compression_formats);
        $this->setName('backup:dump:database')
            ->setDescription('Create a backup file of SugarCRM database')
            ->setHelp(
                <<<EOHELP
Backup the SugarCRM database in to a compressed SQL dump.
The prefix can be set in the configuration file <info>.sugarclirc</info> like this:
<info>
backup:
    prefix: my_prefix
</info>

The tables not dumped with <info>--ignore-for-dev</info> are:

EOHELP
                . implode(
                    '',
                    array_map(
                        function ($table) {
                            return '* <info>' . $table . '</info>' . PHP_EOL;
                        },
                        self::$dev_ignored_tables
                    )
                )
            )
            ->addOption(
                'keep-defaults-file',
                null,
                InputOption::VALUE_NONE,
                'Do not delete the credantials file after completion'
            )
            ->addOption(
                'ignore-table',
                'T',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Tables to ignore.',
                array()
            )
            ->addOption(
                'ignore-for-dev',
                'D',
                InputOption::VALUE_NONE,
                'Ignore tables not useful for a dev environement'
            )
            ;
    }

    protected function buildMysqldumpCommand(InputInterface $input)
    {
        // Get SugarCRM Config
        $sugar_app = $this->getService('sugarcrm.application');
        $this->temp_file = Utils::createTempMySQLDefaultFileFromSugarConfig($sugar_app);
        $this->temp_file->setUnlinkOnDestruct(!$input->getOption('keep-defaults-file'));
        $sugar_config = $sugar_app->getSugarConfig();
        $db_name = $sugar_config['dbconfig']['db_name'];

        $mysqldump_args = array(
            'mysqldump',
            "--defaults-file=" . $this->temp_file->getPathname(),
            '--default-character-set=utf8',
            '--events',
            '--routines',
            '--single-transaction',
            '--opt',
            '--force',
            $db_name,
        );
        $ignore_tables = $input->getOption('ignore-table');
        if ($input->getOption('ignore-for-dev')) {
            $ignore_tables = array_unique(array_merge($ignore_tables, self::$dev_ignored_tables));
        }
        foreach ($ignore_tables as $table) {
            $mysqldump_args[] = "--ignore-table={$db_name}.$table";
        }
        return ProcessBuilder::create($mysqldump_args)->getProcess();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Check compression arg
        $compression = $input->getOption('compression');
        if (!array_key_exists($compression, self::$compression_formats)) {
            throw new \InvalidArgumentException("Invalid compression format '{$compression}'.");
        }
        // Check sugar path
        $sugar_app = $this->getService('sugarcrm.application');
        $sugar_path = $input->getOption('path');
        if (!$sugar_app->isValid()) {
            $output->writeln("<error>No SugarCRM instance found in '{$sugar_path}'.</error>");
            return ExitCode::EXIT_NOT_EXTRACTED;
        }

        $dump_name = $input->getOption('prefix') . '_'
            . gethostname() . '@'
            . $this->getDateTime()->format('Y-m-d_H-i-s')
            . '.sql' . self::$compression_formats[$compression];
        $dump_path = $input->getOption('destination-dir');
        $dump_fullpath = $dump_path . '/' . $dump_name;

        $mysqldump_proc = $this->buildMysqldumpCommand($input);
        // Append | gzip > dumpname
        $mysqldump_proc->setCommandLine(implode(' ', array(
            $mysqldump_proc->getCommandLine(),
            '|', ProcessUtils::escapeArgument($compression),
            '>', ProcessUtils::escapeArgument($dump_fullpath),
        )));

        // Execute mysqldump command
        if ($input->getOption('dry-run')) {
            // Print mysql command and exit
            $output->writeln($mysqldump_proc->getCommandLine());
            return ExitCode::EXIT_SUCCESS;
        }
        // Run in bash to have the pipefail error
        $mysqldump_proc->setInput($mysqldump_proc->getCommandLine());
        $mysqldump_proc->setCommandLine('/bin/bash -o pipefail -o xtrace');
        $mysqldump_proc->setTimeout(0);
        $helper = $this->getHelper('process');
        $helper->mustRun($output, $mysqldump_proc);
        $output->writeln("SugarCRM database backed up in file '$dump_fullpath'");
    }
}
