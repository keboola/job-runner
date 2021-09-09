<?php

declare(strict_types=1);

namespace App\Command;

use App\LogInfo;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\Exception\ClientException;
use Keboola\JobQueueInternalClient\JobFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class CleanupCommand extends Command
{
    /** @inheritdoc */
    protected static $defaultName = 'app:cleanup';
    private LoggerInterface $logger;
    private LogProcessor $logProcessor;
    private QueueClient $queueClient;

    public function __construct(LoggerInterface $logger, LogProcessor $logProcessor, QueueClient $queueClient)
    {
        parent::__construct(self::$defaultName);
        $this->logger = $logger;
        $this->logProcessor = $logProcessor;
        $this->queueClient = $queueClient;
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
        try {
            $jobStatus = $this->queueClient->getJob($jobId)->getStatus();
            if ($jobStatus !== JobFactory::STATUS_TERMINATING) {
                $this->logger->info(
                    sprintf('Job "%s" is in status "%s", letting the job to finish.', $jobId, $jobStatus)
                );
                return 0;
            }
        } catch (ClientException $e) {
            $this->logger->error(sprintf('Failed to get job "%s" for cleanup: ' . $e->getMessage(), $jobId));
            // we don't want the preStop hook to crash
            return 0;
        }
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
