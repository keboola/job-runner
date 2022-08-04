<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneConfigRepository;
use Keboola\JobQueueInternalClient\ExistingJobFactory;
use Keboola\JobQueueInternalClient\JobFactory\JobRuntimeResolver;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptorProvider\DataPlaneObjectEncryptorProvider;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptorProvider\GenericObjectEncryptorProvider;
use Keboola\JobQueueInternalClient\NewJobFactory;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class AbstractCommandTest extends KernelTestCase
{
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
            new ClientOptions((string) getenv('STORAGE_API_URL'))
        );

        $objectEncryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            (string) getenv('ENCRYPTOR_STACK_ID'),
            (string) getenv('AWS_KMS_KEY_ID'),
            (string) getenv('AWS_REGION'),
            null,
            (string) getenv('AZURE_KEY_VAULT_URL'),
        ));

        $dataPlaneConfigRepository = $this->createMock(DataPlaneConfigRepository::class);
        $dataPlaneConfigRepository->expects(self::never())->method(self::anything());

        $newJobFactory = new NewJobFactory(
            $storageClientFactory,
            new JobRuntimeResolver($storageClientFactory),
            new DataPlaneObjectEncryptorProvider(
                $objectEncryptor,
                $dataPlaneConfigRepository,
                false
            ),
        );

        $existingJobFactory = new ExistingJobFactory(
            $storageClientFactory,
            new GenericObjectEncryptorProvider($objectEncryptor)
        );

        $client = new Client(
            new NullLogger(),
            $existingJobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN')
        );

        return [
            'existingJobFactory' => $existingJobFactory,
            'newJobFactory' => $newJobFactory,
            'objectEncryptor' => $objectEncryptor,
            'client' => $client,
        ];
    }
}
