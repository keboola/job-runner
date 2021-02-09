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
        $proc = Process::fromShellCommandline('exec 2> >(tee /proc/1/fd/2)');
        $proc->run();
        $this->logProcessor->setLogInfo(new LogInfo($jobId, '', ''));
        $this->logger->info('Jinkies');
        $this->logger->error('Jinkies2');
        sleep(10);
        return 0;
    }
}
