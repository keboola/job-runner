<?php

declare(strict_types=1);

namespace App\Command;

use App\StorageApiFactory;
use App\StorageApiHandler;
use App\UsageFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class RunCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'app:run';

    /** @var ObjectEncryptorFactory */
    private $objectEncryptorFactory;

    /** @var string */
    private $legacyOauthApiUrl;

    /** @var array */
    private $instanceLimits;

    /** @var QueueClient */
    private $queueClient;

    /** @var Logger */
    private $logger;

    /** @var StorageApiFactory */
    private $storageApiFactory;

    public function __construct(
        Logger $logger,
        ObjectEncryptorFactory $objectEncryptorFactory,
        QueueClient $queueClient,
        StorageApiFactory $storageApiFactory,
        string $legacyOauthApiUrl,
        array $instanceLimits
    ) {
        parent::__construct(self::$defaultName);
        $this->objectEncryptorFactory = $objectEncryptorFactory;
        $this->queueClient = $queueClient;
        $this->logger = $logger;
        $this->storageApiFactory = $storageApiFactory;
        $this->legacyOauthApiUrl = $legacyOauthApiUrl;
        $this->instanceLimits = $instanceLimits;
    }

    protected function configure(): void
    {
        $this->setDescription('Run job')
            ->setHelp('Run job identified by JOB_ID environment variable.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = $this->logger;
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
        // get job
        if (empty(getenv('JOB_ID'))) {
            $output->writeln('JOB_ID env variable is missing.');
            return 2;
        }
        $jobId = getenv('JOB_ID');
        try {
            $logger->info('Running job ' . $jobId);
            $job = $this->queueClient->getJob($jobId);

            $encryptor = $this->initEncryption($job);
            $token = $encryptor->decrypt($job->getTokenString());

            // set up logging to storage API
            $options = [
                'token' => $token,
                'userAgent' => $job->getComponentId(),
            ];
            $clientWithoutLogger = $this->storageApiFactory->getClient($options);
            $clientWithoutLogger->setRunId($jobId);
            $handler = new StorageApiHandler('job-runner', $clientWithoutLogger);
            $logger->pushHandler($handler);
            $containerLogger = new ContainerLogger('container-logger');
            $options['logger'] = $logger;
            $clientWithLogger = $this->storageApiFactory->getClient($options);
            $clientWithLogger->setRunId($jobId);
            $loggerService = new LoggersService($logger, $containerLogger, clone $handler);

            $component = $this->getComponentClass($clientWithoutLogger, $job);
            $jobDefinitions = $this->getJobDefinitions($component, $job, $clientWithoutLogger, $encryptor);

            // set up runner
            $runner = new Runner(
                $this->objectEncryptorFactory,
                $clientWithLogger,
                $loggerService,
                $this->legacyOauthApiUrl,
                $this->instanceLimits
            );
            $usageFile = new UsageFile();
            $usageFile->setQueueClient($this->queueClient);
            $usageFile->setFormat($component->getConfigurationFormat());
            $usageFile->setJobId($job->getId());

            // run job
            $outputs = $runner->run(
                $jobDefinitions,
                'run',
                $job->getMode(),
                $job->getId(),
                $usageFile,
                $job->getConfigRowId()
            );
            if (count($outputs) === 0) {
                $result = [
                    'message' => 'No configurations executed.',
                    'images' => [],
                    'configVersion' => null,
                ];
            } else {
                $result = [
                    'message' => 'Component processing finished.',
                    'images' => array_map(function (Output $output) {
                        return $output->getImages();
                    }, $outputs),
                    'configVersion' => $outputs[0]->getConfigVersion(),
                ];
            }
            $this->queueClient->postJobResult($jobId, JobFactory::STATUS_SUCCESS, $result);
            return 0;
        } catch (\Keboola\ObjectEncryptor\Exception\UserException $e) {
            $logger->error('Job ended with encryption error: ' . $e->getMessage());
            $this->queueClient->postJobResult($jobId, JobFactory::STATUS_ERROR, ['message' => $e->getMessage()]);
            return 1;
        } catch (UserException $e) {
            $logger->error('Job ended with user error: ' . $e->getMessage());
            $this->queueClient->postJobResult($jobId, JobFactory::STATUS_ERROR, ['message' => $e->getMessage()]);
            return 1;
        } catch (Throwable $e) {
            $logger->error('Job ended with application error: ' . $e->getMessage());
            $logger->error($e->getTraceAsString());
            $this->queueClient->postJobResult($jobId, JobFactory::STATUS_ERROR, ['message' => $e->getMessage()]);
            return 2;
        }
    }

    private function getJobDefinitions(
        Component $component,
        Job $job,
        StorageClient $client,
        ObjectEncryptor $encryptor
    ): array {
        $jobDefinitionParser = new JobDefinitionParser();
        if ($job->getConfigData()) {
            $jobDefinitionParser->parseConfigData($component, $job->getConfigData(), $job->getConfigId());
        } else {
            $components = new Components($client);
            $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
            $jobDefinitionParser->parseConfig($component, $encryptor->decrypt($configuration));
        }
        return $jobDefinitionParser->getJobDefinitions();
    }

    private function getComponentClass(StorageClient $client, Job $job): Component
    {
        $component = $this->getComponent($client, $job->getComponentId());
        if (!empty($job->getTag())) {
            $this->logger->warn(sprintf('Overriding component tag with: "%s"', $job->getTag()));
            $component['data']['definition']['tag'] = $job->getTag();
        }
        return new Component($component);
    }

    private function initEncryption(Job $job): ObjectEncryptor
    {
        $this->objectEncryptorFactory->setComponentId($job->getComponentId());
        $this->objectEncryptorFactory->setProjectId($job->getProjectId());
        $this->objectEncryptorFactory->setConfigurationId($job->getConfigId());
        $this->objectEncryptorFactory->setStackId(
            (string) parse_url($this->storageApiFactory->getUrl(), PHP_URL_HOST)
        );
        return $this->objectEncryptorFactory->getEncryptor();
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
}
