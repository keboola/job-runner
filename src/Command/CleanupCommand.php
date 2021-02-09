<?php

declare(strict_types=1);

namespace App\Command;

use App\LogInfo;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class CleanupCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'app:cleanup';

    /** @var LoggerInterface */
    private $logger;

    /** @var LogProcessor */
    private $logProcessor;

    public function __construct(LoggerInterface $logger, LogProcessor $logProcessor)
    {
        parent::__construct(self::$defaultName);
        $this->logger = $logger;
        $this->logProcessor = $logProcessor;
    }

    protected function configure(): void
    {
        $this->setDescription('Cleanup running job')
            ->setHelp('Remove containers from a running job identified by JOB_ID environment variable.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) getenv('JOB_ID');
        if (empty($jobId)) {
            $this->logger->error('The "JOB_ID" environment variable is missing in cleanup command.');
            return 0;
        }
        $proc = Process::fromShellCommandline('exec 1> >(tee /proc/1/fd/1)');
        var_dump($proc->run());
        var_dump($proc->getErrorOutput());
        $proc = Process::fromShellCommandline('exec 2> >(tee /proc/1/fd/2)');
        var_dump($proc->run());
        var_dump($proc->getOutput());
        $proc = Process::fromShellCommandline('ps -ef');
        var_dump($proc->run());
        var_dump($proc->getOutput());

        $this->logProcessor->setLogInfo(new LogInfo($jobId, '', ''));
        $this->logger->info('Jinkies');
        $this->logger->error('Jinkies2');

        //fclose(STDOUT);
        //fclose(STDERR);
        $STDOUT = @fopen('/proc/1/fd/1', 'wb');
        $STDERR = @fopen('/proc/1/fd/2', 'wb');

        if ($STDERR !== false) {
            fwrite($STDERR, "Jinkies3\n");
        }
        if ($STDOUT !== false) {
            fwrite($STDOUT, "Jinkies4\n");
        }
        sleep(10);
        return 0;
    }
}
