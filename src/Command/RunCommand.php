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
use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
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
        $this
            // the short description shown while running "php bin/console list"
            ->setDescription('blabla')
            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('more blablabla');
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

            // init encryption
            $this->objectEncryptorFactory->setComponentId($job->getComponentId());
            $this->objectEncryptorFactory->setProjectId($job->getProjectId());
            $this->objectEncryptorFactory->setConfigurationId($job->getConfigId());
            $this->objectEncryptorFactory->setStackId(parse_url($this->storageApiFactory->getUrl(), PHP_URL_HOST));
            $encryptor = $this->objectEncryptorFactory->getEncryptor();
            $token = $encryptor->decrypt($job->getToken());

            // set up logging to storage
            $options = [
                'url' => $this->storageApiFactory->getUrl(),
                'token' => $token,
                'userAgent' => $job->getComponentId(),
                'jobPollRetryDelay' => self::getStepPollDelayFunction(),
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

            // get job configuration
            $component = $this->getComponent($clientWithoutLogger, $job->getComponentId());
            if (!empty($job->getTag())) {
                $this->logger->warn(sprintf('Overriding component tag with: "%s"', $job->getTag()));
                $component['data']['definition']['tag'] = $job->getTag();
            }
            $componentClass = new Component($component);
            $jobDefinitionParser = new JobDefinitionParser();
            if ($job->getConfigData()) {
                $jobDefinitionParser->parseConfigData($componentClass, $job->getConfigData(), $job->getConfigId());
            } else {
                $components = new Components($clientWithoutLogger);
                $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
                $jobDefinitionParser->parseConfig($componentClass, $encryptor->decrypt($configuration));
            }
            $jobDefinitions = $jobDefinitionParser->getJobDefinitions();

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
            $usageFile->setFormat($componentClass->getConfigurationFormat());
            $usageFile->setJobId($job->getId());

            // run job
            $outputs = $runner->run($jobDefinitions, 'run', $job->getMode(), $job->getId(), $usageFile, $job->getRowId());
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
            // todo postJobResult needs to be called in exception handlers too
            $this->queueClient->postJobResult($jobId, Client::STATUS_SUCCESS, $result);
            return 0;
        } catch (UserException $e) {
            $this->queueClient->postJobResult($jobId, Client::STATUS_ERROR, ['message' => $e->getMessage()]);
            $logger->error('Job ended with user error: ' . $e->getMessage());
            return 1;
        } catch (Throwable $e) {
            $this->queueClient->postJobResult($jobId, Client::STATUS_ERROR, ['message' => $e->getMessage()]);
            $logger->error('Job ended with app error: ' . $e->getMessage());
            $logger->error($e->getTraceAsString());
            return 2;
        }
    }

    public static function getStepPollDelayFunction(): callable
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

    /**
     * @param \Keboola\StorageApi\Client $client
     * @param $id
     * @return array
     */
    protected function getComponent(\Keboola\StorageApi\Client $client, string $id): array
    {
        // Check list of components
        $components = $client->indexAction();
        foreach ($components['components'] as $c) {
            if ($c['id'] === $id) {
                $component = $c;
            }
        }
        //if (!isset($component)) {
        //    throw new \Keboola\Syrup\Exception\UserException("Component '{$id}' not found.");
        //}
        return $component;
    }
}
