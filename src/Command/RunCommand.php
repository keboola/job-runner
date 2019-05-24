<?php

declare(strict_types=1);

namespace App\Command;

use App\StorageApiHandler;
use App\UsageFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\Job;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\ObjectEncryptor\Wrapper\ProjectWrapper;
use Keboola\StorageApi\Components;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class RunCommand extends Command
{
    /** @var ObjectEncryptorFactory */
    private $objectEncryptorFactory;

    /** @var string */
    private $storageApiUrl;

    /** @var string */
    private $legacyOauthApiUrl;

    /** @var array */
    private $instanceLimits;

    /** @var string */
    private $jobQueueUrl;

    public function __construct(
        ObjectEncryptorFactory $objectEncryptorFactory,
        string $storageApiUrl,
        string $jobQueueUrl,
        string $legacyOauthApiUrl,
        array $instanceLimits
    ) {
        parent::__construct('app:run');
        $this->objectEncryptorFactory = $objectEncryptorFactory;
        $this->storageApiUrl = $storageApiUrl;
        $this->jobQueueUrl = $jobQueueUrl;
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
            ->setHelp('more blablabla')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger('runner-logger');
        $logger->pushHandler(new StreamHandler("php://stdout", Logger::INFO));
        try {
            // get job
            if (empty(getenv('JOB_ID'))) {
                throw new ApplicationException('JOB_ID env variable is missing.');
            }
            $jobId = getenv('JOB_ID');
            $logger->info('Running job ' . $jobId);

            $queueClient = new Client($this->jobQueueUrl, 'token');
            /** @var Job $job */
            $job = $queueClient->getFakeJobData([$jobId])[0];

            // init encryption
            $this->objectEncryptorFactory->setComponentId($job->getComponentId());
            $this->objectEncryptorFactory->setProjectId($job->getProjectId());
            $this->objectEncryptorFactory->setConfigurationId($job->getConfigId());
            $this->objectEncryptorFactory->setStackId(parse_url($this->storageApiUrl, PHP_URL_HOST));
            $encryptor = $this->objectEncryptorFactory->getEncryptor();
            var_dump($encryptor->encrypt('578-159263-XDgY3z51cUbfiPQ8katmY6Id0w3PeUTd7q0mO4HV', ProjectWrapper::class));
            //var_dump($encryptor->encrypt('HELP_TUTORIAL', ProjectWrapper::class));
            $token = $encryptor->decrypt($job->getToken());

            // set up logging to storage
            $options = [
                'url' => $this->storageApiUrl,
                'token' => $token,
                'userAgent' => $job->getComponentId(),
                'jobPollRetryDelay' => self::getStepPollDelayFunction(),
            ];
            $clientWithoutLogger = new \Keboola\StorageApi\Client($options);
            $handler = new StorageApiHandler('job-runner', $clientWithoutLogger);
            $logger->pushHandler($handler);
            $containerLogger = new ContainerLogger('container-logger', [$handler]);
            $options['logger'] = $logger;
            $clientWithLogger = new \Keboola\StorageApi\Client($options);
            $loggerService = new LoggersService($logger, $containerLogger, $handler);

            // get job configuration
            $component = $this->getComponent($clientWithLogger, $job->getComponentId());
            $componentClass = new Component($component);
            $jobDefinitionParser = new JobDefinitionParser();
            if ($job->getConfigData()) {
                $jobDefinitionParser->parseConfigData($componentClass, $job->getConfigData(), $job->getConfigId());
            } else {
                $components = new Components($clientWithLogger);
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
            $usageFile->setClient($queueClient);
            $usageFile->setFormat($componentClass->getConfigurationFormat());
            $usageFile->setJobId($job->getId());

            // run job
            $runner->run($jobDefinitions, 'run', $job->getMode(), $job->getId(), $usageFile, $job->getRowId());

            $output->writeln('done something');
            return 0;
        } catch (UserException $e) {
            $logger->error('Job ended with user error: ' . $e->getMessage());
            return 1;
        } catch (Throwable $e) {
            $logger->error('Job ended with app error: ' . $e->getMessage());
            $logger->error($e->getTraceAsString());
            return 2;
        }
    }

    /**
     * @param \Keboola\StorageApi\Client $client
     * @param $id
     * @return array
     */
    protected function getComponent(\Keboola\StorageApi\Client $client, $id)
    {
        // Check list of components
        $components = $client->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $id) {
                $component = $c;
            }
        }
        //if (!isset($component)) {
        //    throw new \Keboola\Syrup\Exception\UserException("Component '{$id}' not found.");
        //}
        return $component;
    }

    public static function getStepPollDelayFunction()
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
}
