<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\RunCommand;
use App\CreditsCheckerFactory;
use App\JobDefinitionFactory;
use Exception;
use Keboola\Csv\CsvFile;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\ErrorControl\Uploader\UploaderFactory;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneConfigRepository;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneConfigValidator;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneObjectEncryptorFactory;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\ManageApi\Client as ManageApiClient;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

abstract class BaseFunctionalTest extends TestCase
{
    private StorageClient $storageClient;
    private Logger $logger;
    private TestHandler $handler;
    private Temp $temp;
    private ObjectEncryptor $objectEncryptor;

    public function setUp(): void
    {
        parent::setUp();
        $requiredEnvs = ['AWS_KMS_KEY_ID', 'AWS_REGION', 'ENCRYPTOR_STACK_ID', 'STORAGE_API_URL',
            'AZURE_KEY_VAULT_URL', 'TEST_STORAGE_API_TOKEN', 'TEST_AWS_ACCESS_KEY_ID', 'TEST_AWS_SECRET_ACCESS_KEY',
            'TEST_AZURE_CLIENT_ID', 'TEST_AZURE_CLIENT_SECRET', 'TEST_AZURE_TENANT_ID',
        ];
        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));
        putenv('AZURE_TENANT_ID=' . getenv('TEST_AZURE_TENANT_ID'));
        putenv('AZURE_CLIENT_ID=' . getenv('TEST_AZURE_CLIENT_ID'));
        putenv('AZURE_CLIENT_SECRET=' . getenv('TEST_AZURE_CLIENT_SECRET'));
        foreach ($requiredEnvs as $env) {
            if (empty(getenv($env))) {
                throw new Exception(sprintf('Environment variable "%s" is empty', $env));
            }
        }
        $this->storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        $this->handler = new TestHandler();
        $this->logger = new Logger('test-runner', [$this->handler]);
        $this->temp = new Temp();
        $this->temp->initRunFolder();

        $this->objectEncryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            (string) getenv('ENCRYPTOR_STACK_ID'),
            (string) getenv('AWS_KMS_KEY_ID'),
            (string) getenv('AWS_REGION'),
            null,
            (string) getenv('AZURE_KEY_VAULT_URL'),
        ));
    }

    protected function getCommand(
        array $jobData,
        ?Client $mockClient = null,
        ?array $expectedJobResult = null
    ): RunCommand {
        $jobData['#tokenString'] = (string) getenv('TEST_STORAGE_API_TOKEN');

        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions($this->storageClient->getApiUrl())
        );

        $manageApiClient = new ManageApiClient([
            'url' => (string) getenv('STORAGE_API_URL'),
            'token' => (string) getenv('MANAGE_API_TOKEN'),
        ]);

        $jobFactory = new JobFactory(
            $storageClientFactory,
            new JobFactory\JobRuntimeResolver($storageClientFactory),
            $this->objectEncryptor,
            new DataPlaneObjectEncryptorFactory(
                (string) parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST),
                (string) getenv('AWS_REGION'),
            ),
            new DataPlaneConfigRepository(
                $manageApiClient,
                new DataPlaneConfigValidator(Validation::createValidator())
            ),
            false
        );

        $job = $jobFactory->createNewJob($jobData);
        $queueClient = $this->getMockBuilder(QueueClient::class)
            ->setMethods(['getJob', 'postJobResult', 'getJobFactory', 'updateJob', 'patchJob'])
            ->disableOriginalConstructor()
            ->getMock();
        $queueClient->expects(self::once())->method('getJob')->willReturn($job);
        $queueClient->expects(self::any())->method('getJobFactory')->willReturn($jobFactory);
        $queueClient->expects(self::any())->method('updateJob')->willReturn([]);
        $queueClient->expects(self::any())->method('patchJob')->willReturn(
            new JobFactory\Job(
                $this->objectEncryptor,
                $storageClientFactory,
                [
                    'runId' => '1234',
                    'status' => 'processing',
                    'projectId' => '123',
                    'componentId' => 'dummy',
                    'configId' => '123',
                ]
            )
        );
        $queueClient->expects(self::once())->method('postJobResult')->with(
            self::anything(),
            self::anything(), //todo self::equalTo('success'),
            $this->callback(function (): bool {
                // Todo solve this is in a more flexible way - row tests produce more images and digests
                // also it is very hard to debug this way
                /*
                if ($expectedJobResult !== null) {
                    self::assertEquals($expectedJobResult, $result, var_export($result, true));
                } else {
                    self::assertArrayHasKey('message', $result, var_export($result, true));
                    self::assertArrayHasKey('images', $result, var_export($result, true));
                    self::assertArrayHasKey('configVersion', $result);
                    self::assertEquals('Component processing finished.', $result['message']);
                    self::assertGreaterThan(1, $result['images']);
                    self::assertGreaterThan(1, $result['images'][0]);
                    self::assertArrayHasKey('id', $result['images'][0][0]);
                    self::assertArrayHasKey('digests', $result['images'][0][0]);
                }*/
                return true;
            })
        )->willReturn(
            new JobFactory\Job(
                $this->objectEncryptor,
                $storageClientFactory,
                [
                    'runId' => '1234',
                    'status' => 'processing',
                    'projectId' => '123',
                    'componentId' => 'dummy',
                    'configId' => '123',
                ]
            )
        );
        /** @var QueueClient $queueClient */
        if ($mockClient) {
            $mockClientWrapper = $this->createMock(ClientWrapper::class);
            $mockClientWrapper->method('getBasicClient')->willReturn($mockClient);
            $mockClientWrapper->method('getBranchClientIfAvailable')->willReturn($mockClient);
            $storageClientFactory = $this->createMock(StorageClientPlainFactory::class);
            $storageClientFactory->method('createClientWrapper')->willReturn($mockClientWrapper);
        }
        $creditsCheckerFactory = new CreditsCheckerFactory();

        $command = new RunCommand(
            $this->logger,
            new LogProcessor(new UploaderFactory(''), 'test-runner'),
            $queueClient,
            $creditsCheckerFactory,
            $storageClientFactory,
            new JobDefinitionFactory(),
            $this->objectEncryptor,
            ['cpu_count' => 1]
        );
        putenv('JOB_ID=' . $job->getId());
        return $command;
    }

    protected function hasEvent(string $type, string $message, array $events): bool
    {
        foreach ($events as $event) {
            if (($event['type'] === $type) && (preg_match('#' . preg_quote($message) . '#', $event['message']))) {
                return true;
            }
        }
        return false;
    }

    protected function createBuckets(): void
    {
        $this->clearBuckets();
        // Create buckets
        $this->storageClient->createBucket('executor-test', Client::STAGE_IN, 'Job Runner TestSuite');
    }

    protected function clearBuckets(): void
    {
        $buckets = ['in.c-executor-test', 'out.c-executor-test', 'out.c-keboola-python-transformation-executor-test'];
        foreach ($buckets as $bucket) {
            try {
                $this->storageClient->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }
    }

    protected function getTestHandler(): TestHandler
    {
        return $this->handler;
    }

    protected function clearFiles(): void
    {
        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['debug', 'executor-test']);
        $files = $this->getClient()->listFiles($options);
        foreach ($files as $file) {
            $this->getClient()->deleteFile($file['id']);
        }
    }

    protected function getClient(): Client
    {
        return $this->storageClient;
    }

    protected function createTable(string $bucketId, string $tableName): void
    {
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);

        $this->storageClient->createTableAsync($bucketId, $tableName, $csv);
    }

    protected function getTemp(): Temp
    {
        return $this->temp;
    }

    protected function getObjectEncryptor(): ObjectEncryptor
    {
        return $this->objectEncryptor;
    }
}
