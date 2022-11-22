<?php

declare(strict_types=1);

namespace App\Command;

use App\CreditsCheckerFactory;
use App\Helper\BuildBranchClientOptionsHelper;
use App\Helper\ExceptionConverter;
use App\Helper\OutputResultConverter;
use App\JobDefinitionFactory;
use App\LogInfo;
use App\StorageApiHandler;
use App\UsageFile;
use App\UuidGenerator;
use Keboola\ConfigurationVariablesResolver\SharedCodeResolver;
use Keboola\ConfigurationVariablesResolver\VariableResolver;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
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
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobPatchData;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Throwable;

class RunCommand extends Command
{
    /** @inheritdoc */
    protected static $defaultName = 'app:run';
    private array $instanceLimits;
    private QueueClient $queueClient;
    private Logger $logger;
    private LogProcessor $logProcessor;
    private CreditsCheckerFactory $creditsCheckerFactory;
    private JobDefinitionFactory $jobDefinitionFactory;
    private ObjectEncryptor $objectEncryptor;
    private StorageClientPlainFactory $storageClientFactory;
    private string $jobId;
    private string $storageApiToken;
    private UuidGenerator $uuidGenerator;

    public function __construct(
        LoggerInterface $logger,
        LogProcessor $logProcessor,
        QueueClient $queueClient,
        CreditsCheckerFactory $creditsCheckerFactory,
        StorageClientPlainFactory $storageClientFactory,
        JobDefinitionFactory $jobDefinitionFactory,
        ObjectEncryptor $objectEncryptor,
        string $jobId,
        string $storageApiToken,
        array $instanceLimits,
        UuidGenerator $uuidGenerator
    ) {
        parent::__construct(self::$defaultName);
        $this->queueClient = $queueClient;
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->logger = $logger;
        $this->creditsCheckerFactory = $creditsCheckerFactory;
        $this->storageClientFactory = $storageClientFactory;
        $this->jobDefinitionFactory = $jobDefinitionFactory;
        $this->objectEncryptor = $objectEncryptor;
        $this->instanceLimits = $instanceLimits;
        $this->logProcessor = $logProcessor;

        pcntl_signal(SIGTERM, [$this, 'terminationHandler']);
        pcntl_signal(SIGINT, [$this, 'terminationHandler']);
        pcntl_async_signals(true);
        $this->jobId = $jobId;
        $this->storageApiToken = $storageApiToken;
        $this->uuidGenerator = $uuidGenerator;
    }

    public function terminationHandler(int $signalNumber): void
    {
        $this->logger->notice(sprintf('Received signal "%s"', $signalNumber));
        $this->logProcessor->setLogInfo(new LogInfo($this->jobId, '', ''));
        try {
            $jobStatus = $this->queueClient->getJob($this->jobId)->getStatus();
            if ($jobStatus !== JobInterface::STATUS_TERMINATING) {
                $this->logger->info(
                    sprintf('Job "%s" is in status "%s", letting the job to finish.', $this->jobId, $jobStatus)
                );
                return;
            }
        } catch (ClientException $e) {
            $this->logger->error(sprintf('Failed to get job "%s" for cleanup: ' . $e->getMessage(), $this->jobId));
            // we don't want the handler to crash
            return;
        }
        $this->logger->info(sprintf('Terminating containers for job "%s".', $this->jobId));
        $process = Process::fromShellCommandline(
            sprintf(
                'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
                escapeshellcmd($this->jobId)
                // intentionally using escapeshellcmd() instead of escapeshellarg(), value is already quoted
            )
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
            return;
        }
        $containerIds = explode("\n", $process->getOutput());
        foreach ($containerIds as $containerId) {
            if (empty(trim($containerId))) {
                continue;
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
                    ]
                );
            }
        }
        $this->logger->info(sprintf('Finished container cleanup for job "%s".', $this->jobId));
        exit;
    }

    protected function configure(): void
    {
        $this->setDescription('Run job')
            ->setHelp('Run job identified by JOB_ID environment variable.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Output[] $outputs */
        $outputs = [];
        $job = null;
        $runnerId = $this->uuidGenerator->generateUuidV4();

        try {
            $this->logger->info(sprintf(
                'Runner ID "%s": Running job "%s".',
                $runnerId,
                $this->jobId
            ));

            $job = $this->queueClient->getJob($this->jobId);
            $job = $this->queueClient->patchJob(
                $job->getId(),
                (new JobPatchData())
                    ->setStatus(JobInterface::STATUS_PROCESSING)
                    ->setRunnerId($runnerId)
            );

            // set up logging to storage API
            $this->logProcessor->setLogInfo(new LogInfo(
                $job->getId(),
                $job->getComponentId(),
                $job->getProjectId()
            ));

            $options = BuildBranchClientOptionsHelper::buildFromJob($job)->setToken($this->storageApiToken);
            $clientWithoutLogger = $this->storageClientFactory
                ->createClientWrapper($options)->getBranchClientIfAvailable();
            $handler = new StorageApiHandler('job-runner', $clientWithoutLogger);
            $this->logger->pushHandler($handler);
            $containerLogger = new ContainerLogger('container-logger');
            $options = clone $options;
            $options->setLogger($this->logger);
            $clientWrapper = $this->storageClientFactory->createClientWrapper($options);
            $loggerService = new LoggersService($this->logger, $containerLogger, clone $handler);

            $creditsChecker = $this->creditsCheckerFactory->getCreditsChecker($clientWrapper->getBasicClient());
            if (!$creditsChecker->hasCredits()) {
                throw new UserException('You do not have credits to run a job');
            }

            // set up runner
            $component = $this->getComponentClass($clientWrapper, $job);
            $jobDefinitions = $this->jobDefinitionFactory->createFromJob(
                $component,
                $job,
                $this->objectEncryptor,
                $clientWrapper
            );

            $runner = new Runner(
                $this->objectEncryptor,
                $clientWrapper,
                $loggerService,
                new OutputFilter(60000),
                $this->instanceLimits
            );
            $usageFile = new UsageFile();
            $usageFile->setQueueClient($this->queueClient);
            $usageFile->setFormat($component->getConfigurationFormat());
            $usageFile->setJobId($job->getId());

            // resolve variables and shared code
            $jobDefinitions = $this->resolveVariables(
                $clientWrapper,
                $jobDefinitions,
                $job->getVariableValuesId(),
                $job->getVariableValuesData()
            );

            // run job
            $runner->run(
                $jobDefinitions,
                'run',
                $job->isInRunMode() ? JobInterface::MODE_RUN : JobInterface::MODE_DEBUG,
                $job->getId(),
                $usageFile,
                $job->getConfigRowIds(),
                $outputs,
                $job->getBackend()->getContainerType(),
                $job->getOrchestrationJobId()
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
                $metrics
            );
        }
        // end with success so that there are no restarts
        return 0;
    }

    private function postJobResult(string $jobId, string $status, JobResult $result, ?JobMetrics $metrics = null): void
    {
        try {
            $this->queueClient->postJobResult($jobId, $status, $result, $metrics);
        } catch (StateTerminalException $e) {
            $this->logger->notice(
                sprintf(
                    'Failed to save result for job "%s". Job has already reached terminal state: "%s"',
                    $jobId,
                    $e->getMessage()
                )
            );
        } catch (StateTransitionForbiddenException $e) {
            $this->logger->notice(
                sprintf(
                    'Failed to save result for job "%s". State transition forbidden: "%s"',
                    $jobId,
                    $e->getMessage()
                )
            );
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Failed to save result for job "%s". Error: "%s".', $jobId, $e->getMessage())
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
        $componentsApi = new Components($clientWrapper->getBasicClient());
        try {
            return $componentsApi->getComponent($id);
        } catch (ClientException $e) {
            throw new UserException(sprintf('Cannot get component "%s": %s.', $id, $e->getMessage()), $e);
        }
    }

    /**
     * @param array<JobDefinition> $jobDefinitions
     * @return array<JobDefinition>
     */
    private function resolveVariables(
        ClientWrapper $clientWrapper,
        array $jobDefinitions,
        ?string $variableValuesId,
        array $variableValuesData
    ): array {
        $sharedCodeResolver = new SharedCodeResolver($clientWrapper, $this->logger);
        $variableResolver = new VariableResolver($clientWrapper, $this->logger);

        $newJobDefinitions = [];
        foreach ($jobDefinitions as $jobDefinition) {
            $newConfiguration = $variableResolver->resolveVariables(
                $sharedCodeResolver->resolveSharedCode($jobDefinition->getConfiguration()),
                $variableValuesId,
                $variableValuesData
            );
            $newJobDefinitions[] = new JobDefinition(
                $newConfiguration,
                $jobDefinition->getComponent(),
                $jobDefinition->getConfigId(),
                $jobDefinition->getConfigVersion(),
                $jobDefinition->getState(),
                $jobDefinition->getRowId(),
                $jobDefinition->isDisabled()
            );
        }

        return $newJobDefinitions;
    }
}
