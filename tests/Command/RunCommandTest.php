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
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertEquals(2, $ret);
        $records = $handler->getRecords();
        $errorRecord = null;
        foreach ($records as $record) {
            if ($record['message'] === 'Job ended with application error: The "JOB_ID" environment variable is missing.') {
                $errorRecord = $record;
            }
        }
        self::assertArrayHasKey('context', $errorRecord, print_r($records, true));
        self::assertStringStartsWith('https://connection', $errorRecord['context']['attachment']);
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
            'componentId' => 'keboola.ex-http',
            'tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
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

        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertTrue($testHandler->hasInfoThatContains('Output mapping done.'));
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
            'tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
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

        self::assertTrue($testHandler->hasInfoThatContains('Job is already running'));
        self::assertFalse($testHandler->hasInfoThatContains('Output mapping done.'));
        self::assertEquals(0, $ret);
    }
}
