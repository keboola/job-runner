<?php

declare(strict_types=1);

namespace App\Command;

use App\LogInfo;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
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
        $this->logProcessor->setLogInfo(new LogInfo($jobId, '', ''));
        $this->logger->info(sprintf('Terminating containers for job "%s".', $jobId));
        $process = Process::fromShellCommandline(
            sprintf(
                'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
                escapeshellcmd($jobId)
                // intentionally using escapeshellcmd() instead of escapeshellarg(), value is already quoted
            )
        );
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error(sprintf('Failed to list containers for job "%s".', $jobId));
        }
        $containerIds = explode("\n", $process->getOutput());
        foreach ($containerIds as $containerId) {
            if (empty(trim($containerId))) {
                continue;
            }
            $this->logger->info(sprintf('Terminating container "%s".', $containerId));
            $process = new Process(['docker', 'stop', $containerId]);
            try {
                $process->mustRun();
            } catch (ProcessFailedException $e) {
                $this->logger->error(
                    sprintf('Failed to terminate container "%s": %s.', $containerId, $e->getMessage())
                );
            }
        }
        $this->logger->info(sprintf('Finished container cleanup for job "%s".', $jobId));
        return 0;
    }
}