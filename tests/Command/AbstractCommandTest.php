<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneConfigRepository;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneConfigValidator;
use Keboola\JobQueueInternalClient\DataPlane\DataPlaneObjectEncryptorFactory;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\ManageApi\Client as ManageApiClient;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validation;

abstract class AbstractCommandTest extends KernelTestCase
{
    /**
     * @return array{factory: JobFactory, client: Client}
     */
    protected function getJobFactoryAndClient(): array
    {
        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions((string) getenv('STORAGE_API_URL'))
        );

        $manageApiClient = new ManageApiClient([
            'url' => (string) getenv('STORAGE_API_URL'),
            'token' => (string) getenv('MANAGE_API_TOKEN'),
        ]);

        $objectEncryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            (string) getenv('ENCRYPTOR_STACK_ID'),
            (string) getenv('AWS_KMS_KEY_ID'),
            (string) getenv('AWS_REGION'),
            null,
            (string) getenv('AZURE_KEY_VAULT_URL'),
        ));

        $jobFactory = new JobFactory(
            $storageClientFactory,
            new JobFactory\JobRuntimeResolver($storageClientFactory),
            $objectEncryptor,
            new DataPlaneObjectEncryptorFactory(
                (string) parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST),
                (string) getenv('AWS_REGION'),
            ),
            new DataPlaneConfigRepository(
                $manageApiClient,
                new DataPlaneConfigValidator(Validation::createValidator())
            ),
            false
        );

        $client = new Client(
            new NullLogger(),
            $jobFactory,
            (string) getenv('JOB_QUEUE_URL'),
            (string) getenv('JOB_QUEUE_TOKEN')
        );

        return [
            'factory' => $jobFactory,
            'objectEncryptor' => $objectEncryptor,
            'client' => $client,
        ];
    }
}
