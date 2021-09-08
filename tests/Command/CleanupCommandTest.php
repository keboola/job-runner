<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\JobFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class CleanupCommandTest extends AbstractCommandTest
{
    public function testExecuteSuccessTerminatingJob(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $storageClient = $storageClientFactory->getClient((string) getenv('TEST_STORAGE_API_TOKEN'));
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $tokenInfo = $storageClient->verifytoken();
        $id = $storageClient->generateId();
        $job = $jobFactory->loadFromExistingJobData([
            'id' => $id,
            'runId' => $id,
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'status' => JobFactory::STATUS_TERMINATING,
            'desiredStatus' => JobFactory::DESIRED_STATUS_TERMINATING,
            'mode' => 'run',
            'configId' => 'dummy',
        ]);
        $job = $client->createJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:cleanup');
        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandText = 'docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=30 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $process = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $process->setTimeout(600); // to pull the image if necessary
        $process->mustRun();
        $process = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $process->mustRun();
        $process = Process::fromShellCommandline(sprintf($commandText, 4321));
        $process->mustRun();
        $process = Process::fromShellCommandline(
            sprintf('docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"', $job->getId())
        );
        $process->mustRun();
        $toRemove = explode("\n", trim($process->getOutput()));
        $process = Process::fromShellCommandline(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"'
        );
        $process->mustRun();
        $toKeep = explode("\n", trim($process->getOutput()));

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);
        self::assertTrue($testHandler->hasInfoThatContains(
            sprintf('Terminating containers for job "%s"', $job->getId())
        ));
        self::assertTrue($testHandler->hasInfoThatContains('Terminating container'));
        self::assertTrue($testHandler->hasInfoThatContains(
            sprintf('Finished container cleanup for job "%s"', $job->getId())
        ));
        self::assertEquals(0, $ret);
        $process = Process::fromShellCommandline('docker ps --format "{{.ID}}"');
        $process->mustRun();
        $containers = explode("\n", $process->getOutput());
        self::assertContains($toKeep[0], $containers);
        self::assertCount(2, $toRemove);
        foreach ($toRemove as $containerId) {
            self::assertNotContains($containerId, $containers);
        }
        putenv('JOB_ID=');
    }

    public function testExecuteSuccessNonTerminatingJob(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $storageClient = $storageClientFactory->getClient((string) getenv('TEST_STORAGE_API_TOKEN'));
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $tokenInfo = $storageClient->verifytoken();
        $id = $storageClient->generateId();
        $job = $jobFactory->loadFromExistingJobData([
            'id' => $id,
            'runId' => $id,
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'status' => JobFactory::STATUS_PROCESSING,
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
        $job = $client->createJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:cleanup');
        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandText = 'docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=30 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $process = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $process->setTimeout(600); // to pull the image if necessary
        $process->mustRun();
        $process = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $process->mustRun();
        $process = Process::fromShellCommandline(sprintf($commandText, 4321));
        $process->mustRun();
        $process = Process::fromShellCommandline(
            sprintf('docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"', $job->getId())
        );
        $process->mustRun();
        $jobContainers = explode("\n", trim($process->getOutput()));
        $process = Process::fromShellCommandline(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"'
        );
        $process->mustRun();
        $nonJobContainers = explode("\n", trim($process->getOutput()));

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);
        self::assertFalse($testHandler->hasInfoThatContains(
            sprintf('Terminating containers for job "%s"', $job->getId())
        ));
        self::assertFalse($testHandler->hasInfoThatContains('Terminating container'));
        self::assertFalse($testHandler->hasInfoThatContains(
            sprintf('Finished container cleanup for job "%s"', $job->getId())
        ));
        self::assertTrue($testHandler->hasInfoThatContains(
            sprintf('Job "%s" is in status "processing", letting the job to finish.', $job->getId())
        ));
        self::assertEquals(0, $ret);
        $process = Process::fromShellCommandline('docker ps --format "{{.ID}}"');
        $process->mustRun();
        $containers = explode("\n", $process->getOutput());
        // all containers are preserved (no cleanup occurred)
        self::assertGreaterThan(1, array_intersect($nonJobContainers, $containers));
        self::assertCount(2, array_intersect($jobContainers, $containers));
        putenv('JOB_ID=');
    }

    public function testExecuteSuccessInvalid(): void
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:cleanup');
        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=<>!$@%#^$');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasInfoThatContains('Terminating containers for job "<>!$@%#^$".'));
        self::assertTrue($testHandler->hasInfoThatContains('Finished container cleanup for job "<>!$@%#^$".'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteFailure(): void
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:cleanup');
        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasErrorThatContains(
            'The "JOB_ID" environment variable is missing in cleanup command.'
        ));
        self::assertEquals(0, $ret);
    }
}
