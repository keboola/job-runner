<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\RunCommand;
use App\JobDefinitionFactory;
use App\JobDefinitionParser;
use App\Tests\EncryptorOptionsTrait;
use App\Tests\TestEnvVarsTrait;
use Exception;
use Keboola\Csv\CsvFile;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobObjectEncryptor;
use Keboola\JobQueueInternalClient\JobFactory\JobRuntimeResolver;
use Keboola\JobQueueInternalClient\NewJobFactory;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Keboola\Temp\Temp;
use Keboola\VaultApiClient\Variables\VariablesApiClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

abstract class BaseFunctionalTest extends TestCase
{
    use EncryptorOptionsTrait;
    use TestEnvVarsTrait;

    private StorageClient $storageClient;
    private readonly VariablesApiClient $vaultVariablesApiClient;
    private Logger $logger;
    private TestHandler $handler;
    private Temp $temp;
    private ObjectEncryptor $objectEncryptor;

    public function setUp(): void
    {
        parent::setUp();
        $requiredEnvs = [
            'AWS_KMS_KEY_ID', 'AWS_REGION', 'GCP_KMS_KEY_ID', 'ENCRYPTOR_STACK_ID', 'STORAGE_API_URL',
            'AZURE_KEY_VAULT_URL', 'TEST_STORAGE_API_TOKEN', 'TEST_AWS_ACCESS_KEY_ID', 'TEST_AWS_SECRET_ACCESS_KEY',
            'TEST_AZURE_CLIENT_ID', 'TEST_AZURE_CLIENT_SECRET', 'TEST_AZURE_TENANT_ID',
            'TEST_GOOGLE_APPLICATION_CREDENTIALS',
        ];
        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));
        putenv('AZURE_TENANT_ID=' . getenv('TEST_AZURE_TENANT_ID'));
        putenv('AZURE_CLIENT_ID=' . getenv('TEST_AZURE_CLIENT_ID'));
        putenv('AZURE_CLIENT_SECRET=' . getenv('TEST_AZURE_CLIENT_SECRET'));
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . getenv('TEST_GOOGLE_APPLICATION_CREDENTIALS'));
        foreach ($requiredEnvs as $env) {
            if (empty(getenv($env))) {
                throw new Exception(sprintf('Environment variable "%s" is empty', $env));
            }
        }
        $this->storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        $this->vaultVariablesApiClient = new VariablesApiClient(
            self::getRequiredEnv('VAULT_API_URL'),
            self::getRequiredEnv('STORAGE_API_TOKEN'),
        );
        $this->handler = new TestHandler();
        $this->logger = new Logger('test-runner', [$this->handler]);
        $this->temp = new Temp();

        $this->objectEncryptor = ObjectEncryptorFactory::getEncryptor($this->getEncryptorOptions());
    }

    protected function getCommand(
        array $jobData,
        ?Client $basicClientMock = null,
        ?BranchAwareClient $branchAwareClientMock = null,
        ?array $expectedJobResult = null,
    ): RunCommand {
        $jobData['#tokenString'] = (string) getenv('TEST_STORAGE_API_TOKEN');

        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions($this->storageClient->getApiUrl()),
        );

        $jobObjectEncryptor = new JobObjectEncryptor($this->objectEncryptor);

        $newJobFactory = new NewJobFactory(
            $storageClientFactory,
            new JobRuntimeResolver($storageClientFactory),
            $jobObjectEncryptor,
        );

        $job = $newJobFactory->createNewJob($jobData);

        $mockQueueClient = $this->getMockBuilder(QueueClient::class)
            ->onlyMethods(['getJob', 'postJobResult', 'patchJob'])
            ->disableOriginalConstructor()
            ->getMock();
        $mockQueueClient->expects(self::once())->method('getJob')->willReturn($job);
        $mockQueueClient->expects(self::any())
            ->method('patchJob')
            ->with(
                $job->getId(),
                self::callback(function ($jobPatchData) {
                    return !empty($jobPatchData->getRunnerId());
                }),
            )
            ->willReturn(
                new Job(
                    $jobObjectEncryptor,
                    $storageClientFactory,
                    array_merge($job->jsonSerialize(), ['status' => 'processing']),
                ),
            );
        if ($expectedJobResult) {
            $mockQueueClient->expects(self::once())
                ->method('postJobResult')
                ->with(
                    self::anything(),
                    self::anything(),
                    self::callback(function (JobResult $arg) use ($expectedJobResult) {
                        self::assertArrayHasKey('message', $expectedJobResult, 'Expectation format is invalid');
                        self::assertArrayHasKey('configVersion', $expectedJobResult, 'Expectation format is invalid');
                        self::assertIsArray($expectedJobResult['images']);
                        self::assertSame($expectedJobResult['message'], $arg->getMessage());
                        self::assertEquals($expectedJobResult['configVersion'], $arg->getConfigVersion());
                        if ($expectedJobResult['images']) {
                            self::assertGreaterThan(0, count($arg->getImages()));
                            self::assertGreaterThan(0, count($arg->getImages()[0]));
                            foreach ($expectedJobResult['images'] as $index => $image) {
                                self::assertStringStartsWith($image, $arg->getImages()[0][$index]['id']);
                            }
                        }
                        return true;
                    }),
                )
                ->willReturn(
                    new Job(
                        $jobObjectEncryptor,
                        $storageClientFactory,
                        array_merge($job->jsonSerialize(), ['status' => 'processing']),
                    ),
                );
        } else {
            $mockQueueClient->expects(self::once())
                ->method('postJobResult')
                ->willReturn(
                    new Job(
                        $jobObjectEncryptor,
                        $storageClientFactory,
                        array_merge($job->jsonSerialize(), ['status' => 'processing']),
                    ),
                );
        }

        if ($basicClientMock) {
            $clientWrapper = $storageClientFactory->createClientWrapper(
                new ClientOptions(token: (string) getenv('TEST_STORAGE_API_TOKEN')),
            );
            $mockClientWrapper = $this->createMock(ClientWrapper::class);
            $mockClientWrapper->method('getBasicClient')->willReturn($basicClientMock);
            $mockClientWrapper->method('getBranchClient')->willReturn($branchAwareClientMock);
            $mockClientWrapper->method('getTableAndFileStorageClient')->willReturn($basicClientMock);
            $mockClientWrapper->method('getBranchId')->willReturn(
                $clientWrapper->getBranch()->id,
            );
            $mockClientWrapper->method('getDefaultBranch')->willReturn(
                $clientWrapper->getDefaultBranch(),
            );
            $storageClientFactory = $this->createMock(StorageClientPlainFactory::class);
            $storageClientFactory->method('createClientWrapper')->willReturn($mockClientWrapper);
        }

        /** @var QueueClient $mockQueueClient */
        return new RunCommand(
            $this->logger,
            new LogProcessor('test-runner'),
            $mockQueueClient,
            $storageClientFactory,
            new JobDefinitionFactory(
                new JobDefinitionParser(),
                $jobObjectEncryptor,
                $this->vaultVariablesApiClient,
                $this->logger,
            ),
            $this->objectEncryptor,
            $job->getId(),
            (string) getenv('TEST_STORAGE_API_TOKEN'),
            ['cpu_count' => 1],
        );
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
                $this->storageClient->dropBucket($bucket, ['force' => true, 'async' => true]);
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
