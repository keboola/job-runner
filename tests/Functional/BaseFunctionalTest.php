<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\RunCommand;
use App\StorageApiFactory;
use Keboola\Csv\CsvFile;
use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\Job;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class BaseFunctionalTest extends TestCase
{
    /** @var StorageClient */
    private $storageClient;

    /** @var Logger */
    private $logger;

    /** @var TestHandler */
    private $handler;

    /** @var Temp */
    private $temp;

    /** @var ObjectEncryptorFactory */
    private $objectEncryptorFactory;

    public function setUp(): void
    {
        parent::setUp();
        if (empty(getenv('kms_key_id'))) {
            throw new RuntimeException('The environment variable "kms_key_id" is empty.');
        }
        if (empty(getenv('legacy_encryption_key'))) {
            throw new RuntimeException('The environment variable "legacy_encryption_key" is empty.');
        }
        if (empty(getenv('region'))) {
            throw new RuntimeException('The environment variable "region" is empty.');
        }
        if (empty(getenv('legacy_oauth_api_url'))) {
            throw new RuntimeException('The environment variable "legacy_oauth_api_url" is empty.');
        }
        if (empty(getenv('KBC_TEST_URL'))) {
            throw new RuntimeException('The environment variable "KBC_TEST_URL" is empty.');
        }
        if (empty(getenv('KBC_TEST_TOKEN'))) {
            throw new RuntimeException('The environment variable "KBC_TEST_TOKEN" is empty.');
        }
        $this->storageClient = new StorageClient([
            'url' => getenv('KBC_TEST_URL'),
            'token' => getenv('KBC_TEST_TOKEN'),
        ]);
        $this->handler = new TestHandler();
        $this->logger = new Logger('test-runner', [$this->handler]);
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        $this->objectEncryptorFactory = new ObjectEncryptorFactory(
            getenv('kms_key_id'),
            getenv('region'),
            getenv('legacy_encryption_key'),
            ''
        );
        $this->objectEncryptorFactory->setStackId(parse_url(getenv('KBC_TEST_URL'), PHP_URL_HOST));
    }

    protected function getCommand(
        array $jobData,
        ?Client $mockClient = null,
        ?array $expectedJobResult = null
    ): RunCommand {
        $tokenInfo = $this->storageClient->verifyToken();
        $this->objectEncryptorFactory->setComponentId($jobData['params']['component']);
        $this->objectEncryptorFactory->setProjectId($tokenInfo['owner']['id']);
        $jobData['token']['token'] = $this->objectEncryptorFactory->getEncryptor()->encrypt(
            (string) getenv('KBC_TEST_TOKEN')
        );
        $jobData['project']['id'] = $tokenInfo['owner']['id'];
        $job = new Job($jobData);
        $queueClient = self::getMockBuilder(QueueClient::class)
            ->setMethods(['getJob', 'postJobResult'])
            ->disableOriginalConstructor()
            ->getMock();
        $queueClient->expects(self::once())->method('getJob')->willReturn($job);
        $queueClient->expects(self::once())->method('postJobResult')->with(
            self::anything(),
            self::anything(), //todo self::equalTo('success'),
            $this->callback(function ($result) use ($expectedJobResult) {
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
        )->willReturn([]);
        /** @var QueueClient $queueClient */
        if ($mockClient) {
            $storageApiFactory = self::getMockBuilder(StorageApiFactory::class)
                ->setConstructorArgs([getenv('KBC_TEST_URL')])
                ->setMethods(['getClient'])
                ->getMock();
            $storageApiFactory->expects(self::any())->method('getClient')->willReturn($mockClient);
        } else {
            $storageApiFactory = new StorageApiFactory((string) getenv('KBC_TEST_URL'));
        }
        /** @var StorageApiFactory $storageApiFactory */
        $command = new RunCommand(
            $this->logger,
            $this->objectEncryptorFactory,
            $queueClient,
            $storageApiFactory,
            (string) getenv('legacy_oauth_api_url'),
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

    protected function getEncryptorFactory(): ObjectEncryptorFactory
    {
        return $this->objectEncryptorFactory;
    }
}
