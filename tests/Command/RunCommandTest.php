<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends KernelTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));
        putenv('AZURE_TENANT_ID=' . getenv('TEST_AZURE_TENANT_ID'));
        putenv('AZURE_CLIENT_ID=' . getenv('TEST_AZURE_CLIENT_ID'));
        putenv('AZURE_CLIENT_SECRET=' . getenv('TEST_AZURE_CLIENT_SECRET'));
    }

    public function testExecuteFailure(): void
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:run');

        $reflectionProperty = new ReflectionProperty($command, 'logger');
        $reflectionProperty->setAccessible(true);
        /** @var Logger $logger */
        $logger = $reflectionProperty->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $records = $testHandler->getRecords();
        $errorRecord = [];
        foreach ($records as $record) {
            if ($record['message'] === 'Job "" ended with application error: ' .
                '"The "JOB_ID" environment variable is missing."'
            ) {
                $errorRecord = $record;
            }
        }
        self::assertArrayHasKey('context', $errorRecord, print_r($records, true));
        self::assertStringStartsWith('https://connection', $errorRecord['context']['attachment']);
        self::assertTrue($testHandler->hasErrorThatContains('Failed to save result for job ""'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteSuccess(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $objectEncryptor = new ObjectEncryptorFactory(
            (string) getenv('AWS_KMS_KEY'),
            (string) getenv('AWS_REGION'),
            '',
            '',
            (string) getenv('AZURE_KEY_VAULT_URL'),
        );
        $jobFactory = new JobFactory($storageClientFactory, $objectEncryptor);
        $client = new Client(
            new NullLogger(),
            $jobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN')
        );
        $job = $jobFactory->createNewJob([
            'componentId' => 'keboola.runner-config-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'parameters' => [
                    'operation' => 'unsafe-dump-config',
                    'arbitrary' => [
                        '#foo' => 'bar',
                    ],
                ],
            ],
        ]);
        self::assertStringStartsWith('KBC::ProjectSecure', $job->getConfigData()['parameters']['arbitrary']['#foo']);
        $job = $client->createJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $jobRecord = [];
        foreach ($testHandler->getRecords() as $record) {
            if ($record['message'] === 'Output mapping done.') {
                $jobRecord = $record;
            }
        }
        self::assertNotEmpty($jobRecord);
        self::assertEquals('keboola.runner-config-test', $jobRecord['component']);
        self::assertEquals($job->getId(), $jobRecord['runId']);
        self::assertTrue($testHandler->hasInfoThatContains(
            'Config: { " p a r a m e t e r s " : { " a r b i t r a r y " : { " # f o o " : " b a r " }'
        ));
        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteUnEncryptedJobData(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $objectEncryptor = new ObjectEncryptorFactory(
            (string) getenv('AWS_KMS_KEY'),
            (string) getenv('AWS_REGION'),
            '',
            '',
            (string) getenv('AZURE_KEY_VAULT_URL'),
        );
        $jobFactory = new JobFactory($storageClientFactory, $objectEncryptor);
        $client = new Client(
            new NullLogger(),
            $jobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN')
        );
        $storageClient = $storageClientFactory->getClient((string) getenv('TEST_STORAGE_API_TOKEN'));
        $tokenInfo = $storageClient->verifytoken();
        // fabricate an erroneous job which contains unencrypted values
        $job = $jobFactory->loadFromExistingJobData([
            'id' => $storageClient->generateId(),
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'status' => JobFactory::STATUS_CREATED,
            'desiredStatus' => JobFactory::DESIRED_STATUS_PROCESSING,
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'parameters' => [
                    'operation' => 'unsafe-dump-config',
                    'arbitrary' => [
                        '#foo' => 'bar',
                    ],
                ],
            ],
        ]);
        // check that the encrypted value was NOT encrypted
        self::assertEquals('bar', $job->getConfigData()['parameters']['arbitrary']['#foo']);
        $job = $client->createJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasErrorThatContains(
            'Job "' . $job->getId() . '" ended with encryption error: "Value is not an encrypted value."'
        ));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteDoubleFailure(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $objectEncryptor = new ObjectEncryptorFactory(
            (string) getenv('AWS_KMS_KEY'),
            (string) getenv('AWS_REGION'),
            '',
            '',
            (string) getenv('AZURE_KEY_VAULT_URL'),
        );
        $jobFactory = new JobFactory($storageClientFactory, $objectEncryptor);
        $client = new Client(
            new NullLogger(),
            $jobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN')
        );
        $job = $jobFactory->createNewJob([
            'componentId' => 'keboola.ex-http',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'storage' => [],
                'parameters' => [
                    'baseUrl' => 'https://help.keboola.com/',
                    'path' => 'tutorial/opportunity.csv',
                ],
            ],
        ]);
        $job = $client->createJob($job);
        $job = $jobFactory->modifyJob($job, ['status' => JobFactory::STATUS_ERROR]);
        $client->updateJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue(
            $testHandler->hasErrorThatContains('Job "' . $job->getId() . '" ended with application error: "')
        );
        self::assertTrue(
            $testHandler->hasErrorThatContains('Failed to save result for job "' . $job->getId() . '". Error: "')
        );
        self::assertFalse($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteSkip(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $objectEncryptor = new ObjectEncryptorFactory(
            (string) getenv('AWS_KMS_KEY'),
            (string) getenv('AWS_REGION'),
            '',
            '',
            (string) getenv('AZURE_KEY_VAULT_URL'),
        );
        $jobFactory = new JobFactory($storageClientFactory, $objectEncryptor);
        $client = new Client(
            new NullLogger(),
            $jobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN')
        );
        $job = $jobFactory->createNewJob([
            'componentId' => 'keboola.ex-http',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'storage' => [],
                'parameters' => [
                    'baseUrl' => 'https://help.keboola.com/',
                    'path' => 'tutorial/opportunity.csv',
                ],
            ],
        ]);
        $job = $client->createJob($job);
        // set the job to processing, the job will succeed but do nothing
        $job = $jobFactory->modifyJob($job, ['status' => JobFactory::STATUS_PROCESSING]);
        $client->updateJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute(
            ['command' => $command->getName()],
            ['verbosity' => OutputInterface::VERBOSITY_DEBUG,
            'capture_stderr_separately' => true]
        );

        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" is already running'));
        self::assertEquals(0, $ret);
    }
}
