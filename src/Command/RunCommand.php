<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\BuildBranchClientOptionsHelper;
use App\Helper\ExceptionConverter;
use App\Helper\OutputResultConverter;
use App\JobDefinitionFactory;
use App\LogInfo;
use App\StorageApiHandler;
use App\UsageFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\Exception\ClientException;
use Keboola\JobQueueInternalClient\Exception\StateTargetEqualsCurrentException;
use Keboola\JobQueueInternalClient\Exception\StateTerminalException;
use Keboola\JobQueueInternalClient\Exception\StateTransitionForbiddenException;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobPatchData;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Throwable;
use function DDTrace\root_span;

#[AsCommand(name: 'app:run')]
class RunCommand extends Command
{
    private ?Runner $runner = null;

    public function __construct(
        private readonly Logger $logger,
        private readonly LogProcessor $logProcessor,
        private readonly QueueClient $queueClient,
        private readonly StorageClientPlainFactory $storageClientFactory,
        private readonly JobDefinitionFactory $jobDefinitionFactory,
        private readonly ObjectEncryptor $objectEncryptor,
        private readonly string $jobId,
        private readonly string $storageApiToken,
        private readonly array $instanceLimits,
        private readonly ?LoggerInterface $debugLogger = null,
    ) {
        parent::__construct();

        pcntl_signal(SIGTERM, [$this, 'terminationHandler']);
        pcntl_signal(SIGINT, [$this, 'terminationHandler']);
        pcntl_async_signals(true);
    }

    public function terminationHandler(int $signalNumber): void
    {
        $this->logger->notice(sprintf('Received signal "%s"', $signalNumber));
        $this->logProcessor->setLogInfo(new LogInfo($this->jobId, '', ''));
        try {
            $job = $this->queueClient->getJob($this->jobId);
        } catch (ClientException $e) {
            $this->logger->error(sprintf('Failed to get job "%s" for cleanup: ' . $e->getMessage(), $this->jobId));
            // we don't want the handler to crash
            return;
        }

        $jobStatus = $job->getStatus();
        if ($jobStatus !== JobInterface::STATUS_TERMINATING) {
            $this->logger->info(
                sprintf('Job "%s" is in status "%s", letting the job to finish.', $this->jobId, $jobStatus),
            );
            return;
        }

        // set up logging to storage API
        $this->logProcessor->setLogInfo(new LogInfo(
            $job->getId(),
            $job->getComponentId(),
            $job->getProjectId(),
        ));

        $this->logger->info(sprintf('Terminating containers for job "%s".', $this->jobId));
        $containerIds = $this->getContainerIds();

        foreach ($containerIds as $containerId) {
            $this->terminateContainer($containerId);
        }

        if ($this->runner !== null) {
            $this->logger->info(sprintf('Clearing up workspaces for job "%s".', $this->jobId));
            $this->runner->cleanup();
            $this->logger->info(sprintf('Finished cleanup for job "%s".', $this->jobId));
        }

        exit;
    }

    private function getContainerIds(): array
    {
        $process = Process::fromShellCommandline(
            sprintf(
                'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
                escapeshellcmd($this->jobId),
                // intentionally using escapeshellcmd() instead of escapeshellarg(), value is already quoted
            ),
        );
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error(sprintf('Failed to list containers for job "%s".' . json_encode([
                    'error' => $e->getMessage(),
                    'stdout' => $process->getOutput(),
                    'stderr' => $process->getErrorOutput(),
                ]), $this->jobId), [
                'error' => $e->getMessage(),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ]);
            return [];
        }
        return explode("\n", $process->getOutput());
    }

    private function terminateContainer(string $containerId): void
    {
        if (empty(trim($containerId))) {
            return;
        }
        $this->logger->info(sprintf('Terminating container "%s".', $containerId));
        $process = new Process(['sudo', 'docker', 'stop', $containerId]);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                sprintf('Failed to terminate container "%s": %s.', $containerId, $e->getMessage()),
                [
                    'error' => $e->getMessage(),
                    'stdout' => $process->getOutput(),
                    'stderr' => $process->getErrorOutput(),
                ],
            );
        }
    }

    protected function configure(): void
    {
        $this->setDescription('Run job')
            ->setHelp('Run job identified by JOB_ID environment variable.');
    }

    private function getLoggerService(ClientOptions $options): LoggersService
    {
        $clientWithoutLogger = $this->storageClientFactory->createClientWrapper($options)
            ->getBranchClient();
        $handler = new StorageApiHandler('job-runner', $clientWithoutLogger);
        $this->logger->pushHandler($handler);

        /* intentionally leaving this commented out, it's useful for debugging
        $h2 = new StreamHandler('/code/job.log', Logger::DEBUG);
        $this->logger->pushHandler($h2);
        */

        $containerLogger = new ContainerLogger('container-logger');
        return new LoggersService($this->logger, $containerLogger, clone $handler);
    }

    private function getClientWrapper(ClientOptions $options): ClientWrapper
    {
        $options = clone $options;
        $options->setLogger($this->logger);
        return $this->storageClientFactory->createClientWrapper($options);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('\DDTrace\root_span')) {
            $span = root_span();
            if ($span) {
                $span->meta['job.id'] = $this->jobId;
            }
        }

        /** @var Output[] $outputs */
        $outputs = [];
        $job = null;
        $runnerId = (string) Uuid::v4();

        try {
            $this->logger->info(sprintf(
                'Runner ID "%s": Running job "%s".',
                $runnerId,
                $this->jobId,
            ));

            $job = $this->queueClient->getJob($this->jobId);

            if (function_exists('\DDTrace\root_span')) {
                $span = root_span();
                if ($span) {
                    $span->meta['job.componentId'] = $job->getComponentId();
                }
            }

            $job = $this->queueClient->patchJob(
                $job->getId(),
                (new JobPatchData())
                    ->setStatus(JobInterface::STATUS_PROCESSING)
                    ->setRunnerId($runnerId),
            );
            $this->logger->info(sprintf(
                'Job branch is "%s", branch type is "%s"',
                $job->getBranchId(),
                $job->getBranchType()->value,
            ));

            // set up logging to storage API
            $this->logProcessor->setLogInfo(new LogInfo(
                $job->getId(),
                $job->getComponentId(),
                $job->getProjectId(),
            ));
            $options = BuildBranchClientOptionsHelper::buildFromJob($job)->setToken($this->storageApiToken);
            $loggerService = $this->getLoggerService($options);
            $clientWrapper = $this->getClientWrapper($options);

            // set up runner
            $component = $this->getComponentClass($clientWrapper, $job);
            $jobDefinitions = $this->jobDefinitionFactory->createFromJob($component, $job, $clientWrapper);

            $this->debugLogger?->info('Job definitions created');

            $this->runner = new Runner(
                $this->objectEncryptor,
                $clientWrapper,
                $loggerService,
                new OutputFilter(60000),
                $this->instanceLimits,
                debugLogger: $this->debugLogger,
            );
            $usageFile = new UsageFile();
            $usageFile->setQueueClient($this->queueClient);
            $usageFile->setFormat($component->getConfigurationFormat());
            $usageFile->setJobId($job->getId());

            // run job
            $this->runner->run(
                $jobDefinitions,
                'run',
                $job->isInRunMode() ? JobInterface::MODE_RUN : JobInterface::MODE_DEBUG,
                $job->getId(),
                $usageFile,
                $job->getConfigRowIds(),
                $outputs,
                $job->getBackend()->getContainerType(),
                $job->getOrchestrationJobId(),
            );

            $result = OutputResultConverter::convertOutputsToResult($outputs);
            $metrics = OutputResultConverter::convertOutputsToMetrics($outputs, $job->getBackend());
            $this->logger->info(sprintf('Job "%s" execution finished.', $this->jobId));
            $this->postJobResult($this->jobId, JobInterface::STATUS_SUCCESS, $result, $metrics);
        } catch (StateTargetEqualsCurrentException $e) {
            $this->logger->info(sprintf('Job "%s" is already running.', $this->jobId));
        } catch (StateTerminalException $e) {
            $this->logger->info(sprintf('Job "%s" was already executed or is cancelled.', $this->jobId));
        } catch (Throwable $e) {
            $metrics = $job ? OutputResultConverter::convertOutputsToMetrics($outputs, $job->getBackend()) : null;
            $this->postJobResult(
                $this->jobId,
                JobInterface::STATUS_ERROR,
                ExceptionConverter::convertExceptionToResult($this->logger, $e, $this->jobId, $outputs),
                $metrics,
            );
        }
        // end with success so that there are no restarts
        return 0;
    }

    private function postJobResult(string $jobId, string $status, JobResult $result, ?JobMetrics $metrics = null): void
    {
        if (function_exists('\DDTrace\root_span')) {
            $span = root_span();
            if ($span) {
                $span->meta['job.result'] = $status;

                if ($status === JobInterface::STATUS_ERROR) {
                    $span->meta['error.msg'] = $result->getMessage();
                    $span->meta['error.type'] = $result->getErrorType();
                }
            }
        }

        try {
            $this->queueClient->postJobResult($jobId, $status, $result, $metrics);
        } catch (StateTerminalException $e) {
            $this->logger->notice(
                sprintf(
                    'Failed to save result for job "%s". Job has already reached terminal state: "%s"',
                    $jobId,
                    $e->getMessage(),
                ),
            );
        } catch (StateTransitionForbiddenException $e) {
            $this->logger->notice(
                sprintf(
                    'Failed to save result for job "%s". State transition forbidden: "%s"',
                    $jobId,
                    $e->getMessage(),
                ),
            );
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Failed to save result for job "%s". Error: "%s".', $jobId, $e->getMessage()),
            );
        }
    }

    private function getComponentClass(ClientWrapper $clientWrapper, JobInterface $job): Component
    {
        $component = $this->getComponent($clientWrapper, $job->getComponentId());
        if (!empty($job->getTag())) {
            $component['data']['definition']['tag'] = $job->getTag();
        }
        return new Component($component);
    }

    private function getComponent(ClientWrapper $clientWrapper, string $id): array
    {
        $componentsApi = new Components($clientWrapper->getBranchClient());
        try {
            return $componentsApi->getComponent($id);
        } catch (ClientException $e) {
            throw new UserException(sprintf('Cannot get component "%s": %s.', $id, $e->getMessage()), $e);
        }
    }
}
