<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $output = $commandTester->getDisplay();
        $this->assertEquals(2, $ret);
        $this->assertContains('JOB_ID env variable is missing.', $output);
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
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
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
        // when a job runner receives a job it's already marked as processing
        $job = $jobFactory->modifyJob($job, ['status' => JobFactory::STATUS_PROCESSING]);
        $client->updateJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');
        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertEquals(0, $ret);
    }
}
