<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\JobQueueInternalClient\Client as QueueClient;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptor\JobObjectEncryptor;
use Keboola\JobQueueInternalClient\JobPatchData;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class RunnerPatchJobRunnerIdTest extends BaseFunctionalTest
{
    public function testRun(): void
    {
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configData' => [
                'parameters' => [
                    'script' => [
                        'print("Python Hello")',
                    ],
                ],
            ],
            '#tokenString' => (string) getenv('TEST_STORAGE_API_TOKEN'),
        ];
        $job = $this->newJobFactory->createNewJob($jobData);

        $mockQueueClient = $this->getMockBuilder(QueueClient::class)
            ->onlyMethods(['getJob', 'postJobResult', 'patchJob'])
            ->disableOriginalConstructor()
            ->getMock();
        $mockQueueClient->expects(self::once())->method('getJob')->willReturn($job);
        $mockQueueClient->expects(self::any())
            ->method('patchJob')
            ->with(
                $job->getId(),
                (new JobPatchData())
                    ->setStatus(Job::STATUS_PROCESSING)
                    ->setRunnerId('e6c10d12-14c3-423a-a3bd-b39787fb1629')
            )
            ->willReturn(
                new Job(
                    new JobObjectEncryptor($this->objectEncryptor),
                    $this->storageClientFactory,
                    array_merge($job->jsonSerialize(), ['status' => 'processing'])
                )
            );
        $mockQueueClient->expects(self::once())
            ->method('postJobResult')
            ->willReturn(
                new Job(
                    new JobObjectEncryptor($this->objectEncryptor),
                    $this->storageClientFactory,
                    array_merge($job->jsonSerialize(), ['status' => 'processing'])
                )
            );

        $command = $this->getCommand($jobData, null, null, $mockQueueClient);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);

        var_dump($this->getTestHandler()->getRecords());
    }
}
