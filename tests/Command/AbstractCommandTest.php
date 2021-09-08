<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class AbstractCommandTest extends KernelTestCase
{
    /**
     * @return array{factory: JobFactory, client: Client}
     */
    protected function getJobFactoryAndClient(): array
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
        return ['factory' => $jobFactory, 'client' => $client];
    }
}
