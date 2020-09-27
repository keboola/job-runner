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
        $objectEncryptor = new ObjectEncryptorFactory((string) getenv('KMS_KEY'), (string) getenv('REGION'), '', '');
        $jobFactory = new JobFactory($storageClientFactory, $objectEncryptor);
        $client = new Client(
            new NullLogger(),
            $jobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_URL')
        );
        $job = $jobFactory->createNewJob([
            'component' => 'keboola.ex-http',
            'token' => getenv('KBC_TEST_TOKEN'),
            'mode' => 'run',
            'config' => 'dummy',
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
