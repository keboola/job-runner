<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\EncryptorOptionsTrait;
use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\ExistingJobFactory;
use Keboola\JobQueueInternalClient\JobFactory\JobObjectEncryptor;
use Keboola\JobQueueInternalClient\JobFactory\JobRuntimeResolver;
use Keboola\JobQueueInternalClient\NewJobFactory;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class AbstractCommandTest extends KernelTestCase
{
    use EncryptorOptionsTrait;

    /**
     * @return array{
     *     newJobFactory: NewJobFactory,
     *     existingJobFactory: ExistingJobFactory,
     *     objectEncryptor: ObjectEncryptor,
     *     client: Client,
     * }
     */
    protected function getJobFactoryAndClient(): array
    {
        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions((string) getenv('STORAGE_API_URL')),
        );

        $objectEncryptor = ObjectEncryptorFactory::getEncryptor($this->getEncryptorOptions());
        $jobObjectEncryptor = new JobObjectEncryptor($objectEncryptor);

        $newJobFactory = new NewJobFactory(
            $storageClientFactory,
            new JobRuntimeResolver($storageClientFactory),
            $jobObjectEncryptor,
        );

        $existingJobFactory = new ExistingJobFactory(
            $storageClientFactory,
            $jobObjectEncryptor,
        );

        $client = new Client(
            new NullLogger(),
            $existingJobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN'),
            null,
            null,
        );

        return [
            'existingJobFactory' => $existingJobFactory,
            'newJobFactory' => $newJobFactory,
            'objectEncryptor' => $objectEncryptor,
            'client' => $client,
        ];
    }
}
