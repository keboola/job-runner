<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\EncryptorOptionsTrait;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobPatchData;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\PermissionChecker\BranchType;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use RuntimeException;
use Symfony\Component\Process\Process;

class RunCommandTerminateTest extends AbstractCommandTest
{
    // Those two constants control the waiting time for settle
    private const MAX_SETTLE_ATTEMPTS = 50;
    private const MAX_SETTLE_DELAY_SECONDS = 5;

    public function settle(float|bool|int|string $targetValue, callable $getActualValue): void
    {
        $attempt = 1;
        while (true) {
            $actualValue = $getActualValue();
            if ($actualValue === $targetValue) {
                break;
            }

            self::assertIsScalar($actualValue);
            if ($attempt > self::MAX_SETTLE_ATTEMPTS) {
                throw new RuntimeException(sprintf(
                    date('c') . 'Failed to settle condition, actual value "%s" does not match target value "%s".',
                    $actualValue,
                    $targetValue,
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
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . getenv('TEST_GOOGLE_APPLICATION_CREDENTIALS'));

        $objectEncryptor = ObjectEncryptorFactory::getEncryptor($this->getEncryptorOptions());

        $result = $objectEncryptor->encryptForComponent(
            (string) getenv('TEST_STORAGE_API_TOKEN'),
            'keboola.runner-config-test',
        );

        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        putenv('AZURE_CLIENT_ID=');
        putenv('AZURE_CLIENT_SECRET=');
        putenv('AZURE_TENANT_ID=');
        putenv('GOOGLE_APPLICATION_CREDENTIALS=');

        return $result;
    }

    public function testExecuteSuccessTerminatingJob(): void
    {
        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions((string) getenv('STORAGE_API_URL')),
        );
        $storageClient = $storageClientFactory->createClientWrapper(
            new ClientOptions(
                null,
                (string) getenv('TEST_STORAGE_API_TOKEN'),
            ),
        )->getBasicClient();
        ['existingJobFactory' => $existingJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $tokenInfo = $storageClient->verifytoken();
        $id = $storageClient->generateId();

        $job = $existingJobFactory->loadFromExistingJobData([
            'id' => $id,
            'runId' => $id,
            'branchType' => BranchType::DEFAULT->value,
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => $this->getEncryptedToken(),
            'status' => JobInterface::STATUS_CREATED,
            'desiredStatus' => JobInterface::DESIRED_STATUS_PROCESSING,
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
        $commandText = 'sudo docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=300 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->setTimeout(600); // to pull the image if necessary
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, 4321));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf(
            'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
            $job->getId(),
        ));
        $tmpProcess->mustRun();
        $containerIdsToRemove = explode("\n", trim($tmpProcess->getOutput()));

        $tmpProcess = Process::fromShellCommandline(
            'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"',
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
                'GOOGLE_APPLICATION_CREDENTIALS' => getenv('TEST_GOOGLE_APPLICATION_CREDENTIALS'),
                'JOB_ID' => $job->getId(),
                'STORAGE_API_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
            ],
        );
        $mainProcess->start();
        $this->settle(
            JobInterface::STATUS_PROCESSING,
            function () use ($mainProcess, $client, $job): string {
                echo $mainProcess->getOutput();
                echo $mainProcess->getErrorOutput();
                return $client->getJob($job->getId())->getStatus();
            },
        );
        sleep(5); // there is nothing better to check than the job status above, but after that, there are two more
                  // storage API requests that must be done, before runner is actually initialized

        $job = $client->patchJob($job->getId(), (new JobPatchData())
            ->setStatus(JobInterface::STATUS_TERMINATING)
            ->setDesiredStatus(JobInterface::DESIRED_STATUS_TERMINATING));

        $pid = $mainProcess->getPid();
        $tmpProcess = Process::fromShellCommandline('kill -15 ' . $pid);
        $tmpProcess->mustRun();
        $this->settle(true, fn () => $mainProcess->getExitCode() !== null);

        $output = $mainProcess->getOutput();
        self::assertStringContainsString(
            sprintf('Terminating containers for job "%s"', $job->getId()),
            $output,
        );
        self::assertStringContainsString('Terminating container', $output);
        self::assertStringContainsString(
            sprintf('Clearing up workspaces for job "%s"', $job->getId()),
            $output,
        );
        self::assertStringContainsString(
            sprintf('Finished cleanup for job "%s"', $job->getId()),
            $output,
        );
        self::assertEquals(0, $mainProcess->getExitCode());

        $tmpProcess = Process::fromShellCommandline('sudo docker ps --format "{{.ID}}"');
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
        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions((string) getenv('STORAGE_API_URL')),
        );
        $storageClient = $storageClientFactory->createClientWrapper(
            new ClientOptions(
                null,
                (string) getenv('TEST_STORAGE_API_TOKEN'),
            ),
        )->getBasicClient();
        ['existingJobFactory' => $existingJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();
        $tokenInfo = $storageClient->verifytoken();
        $id = $storageClient->generateId();
        $job = $existingJobFactory->loadFromExistingJobData([
            'id' => $id,
            'runId' => $id,
            'branchType' => BranchType::DEFAULT->value,
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => $this->getEncryptedToken(),
            'status' => JobInterface::STATUS_CREATED,
            'desiredStatus' => JobInterface::DESIRED_STATUS_PROCESSING,
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

        $commandText = 'sudo docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=300 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->setTimeout(600); // to pull the image if necessary
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, $job->getId()));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf($commandText, 4321));
        $tmpProcess->mustRun();
        $tmpProcess = Process::fromShellCommandline(sprintf(
            'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=%s"',
            $job->getId(),
        ));
        $tmpProcess->mustRun();
        $jobContainers = explode("\n", trim($tmpProcess->getOutput()));
        $tmpProcess = Process::fromShellCommandline(
            'sudo docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"',
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
                'GOOGLE_APPLICATION_CREDENTIALS' => getenv('TEST_GOOGLE_APPLICATION_CREDENTIALS'),
                'JOB_ID' => $job->getId(),
                'STORAGE_API_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
            ],
        );
        $mainProcess->start();
        $this->settle(
            JobInterface::STATUS_PROCESSING,
            function () use ($mainProcess, $client, $job): string {
                echo $mainProcess->getOutput();
                echo $mainProcess->getErrorOutput();
                return $client->getJob($job->getId())->getStatus();
            },
        );

        $pid = $mainProcess->getPid();
        $tmpProcess = Process::fromShellCommandline('kill -15 ' . $pid);
        $tmpProcess->mustRun();
        $this->settle(true, fn () => $mainProcess->getExitCode() !== null);
        $output = $mainProcess->getOutput();

        self::assertStringNotContainsString(
            sprintf('Terminating containers for job "%s"', $job->getId()),
            $output,
        );
        self::assertStringNotContainsString(
            'Terminating container',
            $output,
        );
        self::assertStringNotContainsString(
            sprintf('Finished container cleanup for job "%s"', $job->getId()),
            $output,
        );
        self::assertStringContainsString(
            sprintf('Job "%s" is in status "processing", letting the job to finish.', $job->getId()),
            $output,
        );
        self::assertEquals(0, $mainProcess->getExitCode());

        $tmpProcess = Process::fromShellCommandline('sudo docker ps --format "{{.ID}}"');
        $tmpProcess->mustRun();
        $containers = explode("\n", $tmpProcess->getOutput());
        // all containers are preserved (no cleanup occurred)
        self::assertGreaterThan(1, array_intersect($nonJobContainers, $containers));
        self::assertCount(2, array_intersect($jobContainers, $containers));
    }
}
