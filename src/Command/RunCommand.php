<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\ExceptionConverter;
use App\Helper\OutputResultConverter;
use App\JobDefinitionFactory;
use App\LogInfo;
use App\StorageApiFactory;
use App\StorageApiHandler;
use App\UsageFile;
use Keboola\ConfigurationVariablesResolver\SharedCodeResolver;
use Keboola\ConfigurationVariablesResolver\VariableResolver;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\Exception\StateTargetEqualsCurrentException;
use Keboola\JobQueueInternalClient\Exception\StateTerminalException;
use Keboola\JobQueueInternalClient\Exception\StateTransitionForbiddenException;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobPatchData;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApiBranch\ClientWrapper;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
    private StorageApiFactory $storageApiFactory;
    private JobDefinitionFactory $jobDefinitionFactory;

    public function __construct(
        LoggerInterface $logger,
        LogProcessor $logProcessor,
        QueueClient $queueClient,
        StorageApiFactory $storageApiFactory,
        JobDefinitionFactory $jobDefinitionFactory,
        string $legacyOauthApiUrl,
        array $instanceLimits
    ) {
        parent::__construct(self::$defaultName);
        $this->queueClient = $queueClient;
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->logger = $logger;
        $this->storageApiFactory = $storageApiFactory;
        $this->jobDefinitionFactory = $jobDefinitionFactory;
        $this->legacyOauthApiUrl = $legacyOauthApiUrl;
        $this->instanceLimits = $instanceLimits;
        $this->logProcessor = $logProcessor;
    }

    protected function configure(): void
    {
        $this->setDescription('Run job')
            ->setHelp('Run job identified by JOB_ID environment variable.');
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
            $options = [
                'token' => $token,
                'userAgent' => $job->getComponentId(),
            ];
            $clientWithoutLogger = $this->storageApiFactory->getClient($options);
            $this->logger->info('Decrypted token ' . $clientWithoutLogger->verifyToken()['description']);
            $clientWithoutLogger->setRunId($job->getRunId());
            $handler = new StorageApiHandler('job-runner', $clientWithoutLogger);
            $this->logger->pushHandler($handler);
            $containerLogger = new ContainerLogger('container-logger');
            $options['logger'] = $this->logger;
            $clientWithLogger = $this->storageApiFactory->getClient($options);
            $clientWithLogger->setRunId($job->getRunId());
            $loggerService = new LoggersService($this->logger, $containerLogger, clone $handler);

            $creditsChecker = $this->storageApiFactory->getCreditsChecker($clientWithLogger);
            if (!$creditsChecker->hasCredits()) {
                throw new UserException('You do not have credits to run a job');
            }

            $component = $this->getComponentClass($clientWithoutLogger, $job);
            $jobDefinitions = $this->jobDefinitionFactory->createFromJob($component, $job, $clientWithoutLogger);

            // set up runner
            $clientWrapper = new ClientWrapper(
                $clientWithLogger,
                null,
                $this->logger,
                $job->getBranchId() ?? ''
            );
            $runner = new Runner(
                $job->getEncryptorFactory(),
                $clientWrapper,
                $loggerService,
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
                $job->getMode(),
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
            $this->logger->debug(
                sprintf(
                    'Failed to save result for job "%s". Job has already reached terminal state: "%s"',
                    $jobId,
                    $e->getMessage()
                )
            );
        } catch (StateTransitionForbiddenException $e) {
            $this->logger->debug(
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

    private function getComponentClass(StorageClient $client, JobInterface $job): Component
    {
        $component = $this->getComponent($client, $job->getComponentId());
        if (!empty($job->getTag())) {
            $this->logger->warn(sprintf('Overriding component tag with: "%s"', $job->getTag()));
            $component['data']['definition']['tag'] = $job->getTag();
        }
        return new Component($component);
    }

    private function getComponent(StorageClient $client, string $id): array
    {
        // Check list of components
        $components = $client->indexAction();
        foreach ($components['components'] as $component) {
            if ($component['id'] === $id) {
                return $component;
            }
        }
        throw new UserException(sprintf('Component "%s" was not found.', $id));
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
