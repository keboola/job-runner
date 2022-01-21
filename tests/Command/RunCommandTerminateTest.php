<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Process\Process;

class RunCommandTerminateTest extends AbstractCommandTest
{
    // Those two constants control the waiting time for settle
    private const MAX_SETTLE_ATTEMPTS = 50;
    private const MAX_SETTLE_DELAY_SECONDS = 5;

    /**
     * @param mixed $targetValue
     */
    public function settle($targetValue, callable $getActualValue): void
    {
        $attempt = 1;
        while (true) {
            $actualValue = $getActualValue();
            if ($actualValue === $targetValue) {
                break;
            }

            if ($attempt > self::MAX_SETTLE_ATTEMPTS) {
                throw new RuntimeException(sprintf(
                    date('c') . 'Failed to settle condition, actual value "%s" does not match target value "%s".',
                    $actualValue,
                    $targetValue
                ));
            }

            sleep((int) min(pow(2, $attempt), self::MAX_SETTLE_DELAY_SECONDS));
            $attempt++;
        }
    }

    private function getEncryptedToken(): string
    {
        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));
        putenv('AZURE_CLIENT_ID=' . getenv('TEST_AZURE_CLIENT_ID'));
        putenv('AZURE_CLIENT_SECRET=' . getenv('TEST_AZURE_CLIENT_SECRET'));
        putenv('AZURE_TENANT_ID=' . getenv('TEST_AZURE_TENANT_ID'));

        $objectEncryptorFactory = new ObjectEncryptorFactory(
            (string) getenv('AWS_KMS_KEY'),
            (string) getenv('AWS_REGION'),
            '',
            '',
            (string) getenv('AZURE_KEY_VAULT_URL'),
        );
        $objectEncryptorFactory->setComponentId('keboola.runner-config-test');
        $objectEncryptorFactory->setStackId((string) parse_url(
            (string) getenv('STORAGE_API_URL'),
            PHP_URL_HOST
        ));
        $result = $objectEncryptorFactory->getEncryptor()->encrypt(
            (string) getenv('TEST_STORAGE_API_TOKEN'),
            $objectEncryptorFactory->getEncryptor()->getRegisteredComponentWrapperClass()
        );

        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        putenv('AZURE_CLIENT_ID=');
        putenv('AZURE_CLIENT_SECRET=');
        putenv('AZURE_TENANT_ID=');

        return $result;
    }

    public function testExecuteSuccessTerminatingJob(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory(
            (string) getenv('STORAGE_API_URL'),
            new NullLogger()
        );
        $storageClient = $storageClientFactory->getClientWrapper(
            (string) getenv('TEST_STORAGE_API_TOKEN'),
            ClientWrapper::BRANCH_MAIN
        )->getBasicClient();
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        /** @var Client $client */
        /** @var JobFactory $jobFactory */

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
            '#tokenString' => $this->getEncryptedToken(),
            'status' => JobFactory::STATUS_CREATED,
            'desiredStatus' => JobFactory::DESIRED_STATUS_PROCESSING,
            'mode' => 'run',
            'configData' => [
                'parameters' => [
                    'operation' => 'sleep',
                    'timeout' => 100,
                ],
            ],
        ]);
        $job = $client->createJob($job);

        /* Create some fake containers to delete and some not to be deleted. Though we can wait for the job to
            actually create containers, we'd still have to create the containers not belonging to the job, so let's
            do them all here.
        */
        $commandText = 'docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=300 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->setTimeout(600); // to pull the image if necessary
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, 4321));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
            $job->getId()
        ));
        $tmpProcess->mustRun();
        $containerIdsToRemove = explode("\n", trim($tmpProcess->getOutput()));

        $tmpProcess = Process::fromShellCommandline(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"'
        );
        $tmpProcess->mustRun();
        $containerIdsToKeep = explode("\n", trim($tmpProcess->getOutput()));

        $mainProcess = new Process(
            ['php', 'bin/console', 'app:run'],
            null,
            [
                'AWS_ACCESS_KEY_ID' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                'AWS_SECRET_ACCESS_KEY' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                'AZURE_CLIENT_ID' => getenv('TEST_AZURE_CLIENT_ID'),
                'AZURE_CLIENT_SECRET' => getenv('TEST_AZURE_CLIENT_SECRET'),
                'AZURE_TENANT_ID' => getenv('TEST_AZURE_TENANT_ID'),
                'JOB_ID' => $job->getId(),
            ]
        );
        $mainProcess->start();
        $this->settle(
            JobFactory::STATUS_PROCESSING,
            function () use ($mainProcess, $client, $job): string {
                echo $mainProcess->getOutput();
                echo $mainProcess->getErrorOutput();
                return $client->getJob($job->getId())->getStatus();
            }
        );

        $job = $jobFactory->modifyJob($job, ['status' => JobFactory::STATUS_TERMINATING,
            'desiredStatus' => JobFactory::DESIRED_STATUS_TERMINATING]);
        $client->updateJob($job);

        $pid = $mainProcess->getPid();
        $tmpProcess = Process::fromShellCommandline('kill -15 ' . $pid);
        $tmpProcess->mustRun();
        $this->settle(true, fn () => $mainProcess->getExitCode() !== null);

        $output = $mainProcess->getOutput();
        self::assertStringContainsString(
            sprintf('Terminating containers for job \"%s\"', $job->getId()),
            $output
        );
        self::assertStringContainsString('Terminating container', $output);
        self::assertStringContainsString(
            sprintf('Finished container cleanup for job \"%s\"', $job->getId()),
            $output
        );
        self::assertEquals(0, $mainProcess->getExitCode());

        $tmpProcess = Process::fromShellCommandline('docker ps --format "{{.ID}}"');
        $tmpProcess->mustRun();
        $containers = explode("\n", $tmpProcess->getOutput());
        self::assertContains($containerIdsToKeep[0], $containers);
        self::assertCount(2, $containerIdsToRemove);
        foreach ($containerIdsToRemove as $containerId) {
            self::assertNotContains($containerId, $containers);
        }
    }

    public function testExecuteSuccessNonTerminatingJob(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory(
            (string) getenv('STORAGE_API_URL'),
            new NullLogger()
        );
        $storageClient = $storageClientFactory->getClientWrapper(
            (string) getenv('TEST_STORAGE_API_TOKEN'),
            ClientWrapper::BRANCH_MAIN
        )->getBasicClient();
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
            '#tokenString' => $this->getEncryptedToken(),
            'status' => JobFactory::STATUS_CREATED,
            'desiredStatus' => JobFactory::DESIRED_STATUS_PROCESSING,
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'parameters' => [
                    'operation' => 'sleep',
                    'timeout' => 100,
                ],
            ],
        ]);
        $job = $client->createJob($job);

        $commandText = 'docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=300 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->setTimeout(600); // to pull the image if necessary
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, 4321));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(
            sprintf('docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"', $job->getId())
        );
        $tmpProcess->mustRun();
        $jobContainers = explode("\n", trim($tmpProcess->getOutput()));
        $tmpProcess = Process::fromShellCommandline(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"'
        );
        $tmpProcess->mustRun();
        $nonJobContainers = explode("\n", trim($tmpProcess->getOutput()));

        $mainProcess = new Process(
            ['php', 'bin/console', 'app:run'],
            null,
            [
                'AWS_ACCESS_KEY_ID' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                'AWS_SECRET_ACCESS_KEY' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                'AZURE_CLIENT_ID' => getenv('TEST_AZURE_CLIENT_ID'),
                'AZURE_CLIENT_SECRET' => getenv('TEST_AZURE_CLIENT_SECRET'),
                'AZURE_TENANT_ID' => getenv('TEST_AZURE_TENANT_ID'),
                'JOB_ID' => $job->getId(),
            ]
        );
        $mainProcess->start();
        $this->settle(
            JobFactory::STATUS_PROCESSING,
            function () use ($mainProcess, $client, $job): string {
                echo $mainProcess->getOutput();
                echo $mainProcess->getErrorOutput();
                return $client->getJob($job->getId())->getStatus();
            }
        );

        $pid = $mainProcess->getPid();
        $tmpProcess = Process::fromShellCommandline('kill -15 ' . $pid);
        $tmpProcess->mustRun();
        $this->settle(true, fn () => $mainProcess->getExitCode() !== null);
        $output = $mainProcess->getOutput();

        self::assertStringNotContainsString(
            sprintf('Terminating containers for job \"%s\"', $job->getId()),
            $output
        );
        self::assertStringNotContainsString(
            'Terminating container',
            $output,
        );
        self::assertStringNotContainsString(
            sprintf('Finished container cleanup for job \"%s\"', $job->getId()),
            $output
        );
        self::assertStringContainsString(
            sprintf('Job \"%s\" is in status \"processing\", letting the job to finish.', $job->getId()),
            $output
        );
        self::assertEquals(0, $mainProcess->getExitCode());

        $tmpProcess = Process::fromShellCommandline('docker ps --format "{{.ID}}"');
        $tmpProcess->mustRun();
        $containers = explode("\n", $tmpProcess->getOutput());
        // all containers are preserved (no cleanup occurred)
        self::assertGreaterThan(1, array_intersect($nonJobContainers, $containers));
        self::assertCount(2, array_intersect($jobContainers, $containers));
    }
}
