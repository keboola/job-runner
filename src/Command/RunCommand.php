<?php

declare(strict_types=1);

namespace App\Command;

use App\CreditsCheckerFactory;
use App\Helper\ExceptionConverter;
use App\Helper\OutputResultConverter;
use App\JobDefinitionFactory;
use App\LogInfo;
use App\StorageApiHandler;
use App\UsageFile;
use Closure;
use Keboola\ConfigurationVariablesResolver\SharedCodeResolver;
use Keboola\ConfigurationVariablesResolver\VariableResolver;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\Exception\ClientException;
use Keboola\JobQueueInternalClient\Exception\StateTargetEqualsCurrentException;
use Keboola\JobQueueInternalClient\Exception\StateTerminalException;
use Keboola\JobQueueInternalClient\Exception\StateTransitionForbiddenException;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobPatchData;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class RunCommand extends Command
{
    /** @inheritdoc */
    protected static $defaultName = 'app:run';
    private string $legacyOauthApiUrl;
    private array $instanceLimits;
    private QueueClient $queueClient;
    private Logger $logger;
    private LogProcessor $logProcessor;
    private CreditsCheckerFactory $creditsCheckerFactory;
    private JobDefinitionFactory $jobDefinitionFactory;
    private StorageClientPlainFactory $storageClientFactory;

    public function __construct(
        LoggerInterface $logger,
        LogProcessor $logProcessor,
        QueueClient $queueClient,
        CreditsCheckerFactory $creditsCheckerFactory,
        StorageClientPlainFactory $storageClientFactory,
        JobDefinitionFactory $jobDefinitionFactory,
        string $legacyOauthApiUrl,
        array $instanceLimits
    ) {
        parent::__construct(self::$defaultName);
        $this->queueClient = $queueClient;
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->logger = $logger;
        $this->creditsCheckerFactory = $creditsCheckerFactory;
        $this->storageClientFactory = $storageClientFactory;
        $this->jobDefinitionFactory = $jobDefinitionFactory;
        $this->legacyOauthApiUrl = $legacyOauthApiUrl;
        $this->instanceLimits = $instanceLimits;
        $this->logProcessor = $logProcessor;

        pcntl_signal(SIGTERM, [$this, 'terminationHandler']);
        pcntl_signal(SIGINT, [$this, 'terminationHandler']);
        pcntl_async_signals(true);
    }

    public function terminationHandler(int $signalNumber): void
    {
        $this->logger->notice(sprintf('Received signal "%s"', $signalNumber));
        $jobId = (string) getenv('JOB_ID');
        if (empty($jobId)) {
            $this->logger->error('The "JOB_ID" environment variable is missing in cleanup command.');
            return;
        }
        $this->logProcessor->setLogInfo(new LogInfo($jobId, '', ''));
        try {
            $jobStatus = $this->queueClient->getJob($jobId)->getStatus();
            if ($jobStatus !== JobFactory::STATUS_TERMINATING) {
                $this->logger->info(
                    sprintf('Job "%s" is in status "%s", letting the job to finish.', $jobId, $jobStatus)
                );
                return;
            }
        } catch (ClientException $e) {
            $this->logger->error(sprintf('Failed to get job "%s" for cleanup: ' . $e->getMessage(), $jobId));
            // we don't want the handler to crash
            return;
        }
        $this->logger->info(sprintf('Terminating containers for job "%s".', $jobId));
        $process = Process::fromShellCommandline(
            sprintf(
                'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
                escapeshellcmd($jobId)
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
                ]), $jobId), [
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
        $this->logger->info(sprintf('Finished container cleanup for job "%s".', $jobId));
        exit;
    }

    protected function configure(): void
    {
        $this->setDescription('Run job')
            ->setHelp('Run job identified by JOB_ID environment variable.');
    }

    private static function getStepPollDelayFunction(): Closure
    {
        return function ($tries) {
            switch ($tries) {
                case ($tries < 15):
                    return 1;
                case ($tries < 30):
                    return 2;
                default:
                    return 5;
            }
        };
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) getenv('JOB_ID');
        /** @var Output[] $outputs */
        $outputs = [];
        try {
            // get job
            if (empty($jobId)) {
                throw new ApplicationException('The "JOB_ID" environment variable is missing.');
            }

            $this->logger->info(sprintf('Running job "%s".', $jobId));
            $job = $this->queueClient->getJob($jobId);
            $token = $job->getTokenDecrypted();
            $job = $this->queueClient->getJobFactory()->modifyJob($job, ['status' => JobFactory::STATUS_PROCESSING]);
            $this->queueClient->patchJob(
                $job->getId(),
                (new JobPatchData())->setStatus($job->getStatus())
            );

            // set up logging to storage API
            $this->logProcessor->setLogInfo(new LogInfo(
                $job->getId(),
                $job->getComponentId(),
                $job->getProjectId()
            ));
            $options = (new ClientOptions())
                ->setToken($token)
                ->setUserAgent($job->getComponentId())
                ->setBranchId($job->getBranchId())
                ->setRunId($job->getRunId())
                ->setJobPollRetryDelay(self::getStepPollDelayFunction());
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
            $jobDefinitions = $this->jobDefinitionFactory->createFromJob($component, $job, $clientWrapper);

            $runner = new Runner(
                $job->getEncryptorFactory(),
                $clientWrapper,
                $loggerService,
                new OutputFilter(60000),
                $this->legacyOauthApiUrl,
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
                $job->isInRunMode() ? Job::MODE_RUN : Job::MODE_DEBUG,
                $job->getId(),
                $usageFile,
                $job->getConfigRowIds(),
                $outputs
            );

            $result = OutputResultConverter::convertOutputsToResult($outputs);
            $metrics = OutputResultConverter::convertOutputsToMetrics($outputs);
            $this->logger->info(sprintf('Job "%s" execution finished.', $jobId));
            $this->postJobResult($jobId, JobFactory::STATUS_SUCCESS, $result, $metrics);
        } catch (StateTargetEqualsCurrentException $e) {
            $this->logger->info(sprintf('Job "%s" is already running', $jobId));
        } catch (Throwable $e) {
            $this->postJobResult(
                $jobId,
                JobFactory::STATUS_ERROR,
                ExceptionConverter::convertExceptionToResult($this->logger, $e, $jobId, $outputs)
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
