<?php

declare(strict_types=1);

namespace App\Tests;

use App\JobDefinitionFactory;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class JobDefinitionFactoryTest extends TestCase
{
    public function testCreateJobDefinitionWithConfigData(): void
    {
        $configData = [
            'runtime' => [
                'foo' => 'bar',
            ],
        ];

        $jobData = [
            'status' => JobFactory::STATUS_CREATED,
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
            'configData' => $configData,
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfigData($jobData, $configData);

        self::assertCount(1, $jobDefinitions);
        $jobDefinition = $jobDefinitions[0];

        self::assertSame($jobData['configId'], $jobDefinition->getConfigId());
        self::assertSame($jobData['componentId'], $jobDefinition->getComponentId());
        self::assertSame('bar', $jobDefinition->getConfiguration()['runtime']['foo'] ?? null);
    }

    public function testCreateJobDefinitionWithConfigDataAndBackend(): void
    {
        $configData = [
            'runtime' => [
                'foo' => 'bar',
                'backend' => [
                    'type' => 'invalid',
                ],
            ],
        ];

        $jobData = [
            'status' => JobFactory::STATUS_CREATED,
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
            'configData' => $configData,
            'backend' => [
                'type' => 'custom',
            ],
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfigData($jobData, $configData);
        self::assertSame(
            'custom',
            $jobDefinitions[0]->getConfiguration()['runtime']['backend']['type'] ?? null
        );
    }

    public function testCreateJobDefinitionWithConfigId(): void
    {
        $configuration = [
            'id' => 'my-config',
            'version' => '1',
            'state' => [],
            'rows' => [],
            'configuration' => [
                'runtime' => [
                    'foo' => 'bar',
                ],
            ],
        ];

        $jobData = [
            'status' => JobFactory::STATUS_CREATED,
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfiguration($jobData, $configuration);

        self::assertCount(1, $jobDefinitions);
        $jobDefinition = $jobDefinitions[0];

        self::assertSame($jobData['configId'], $jobDefinition->getConfigId());
        self::assertSame($jobData['componentId'], $jobDefinition->getComponentId());
        self::assertSame('bar', $jobDefinition->getConfiguration()['runtime']['foo'] ?? null);
    }

    public function testCreateJobDefinitionWithConfigIdAndBackend(): void
    {
        $configuration = [
            'id' => 'my-config',
            'version' => '1',
            'state' => [],
            'rows' => [],
            'runtime' => [
                'foo' => 'bar',
                'backend' => [
                    'type' => 'invalid',
                ],
            ],
        ];

        $jobData = [
            'status' => JobFactory::STATUS_CREATED,
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
            'backend' => [
                'type' => 'custom',
            ],
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfiguration($jobData, $configuration);

        self::assertSame(
            'custom',
            $jobDefinitions[0]->getConfiguration()['runtime']['backend']['type'] ?? null
        );
    }

    /**
     * @return array<JobDefinition>
     */
    private function createJobDefinitionsWithConfigData(array $jobData, array $configData): array
    {
        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decrypt')->with($configData)->willReturn($configData);

        $encryptorFactory = $this->createMock(ObjectEncryptorFactory::class);
        $encryptorFactory->method('getEncryptor')->willReturn($encryptor);

        $component = new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ]);

        $job = new Job($encryptorFactory, $jobData);

        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->expects(self::never())->method(self::anything());

        $factory = new JobDefinitionFactory();
        return $factory->createFromJob($component, $job, $storageApiClient);
    }

    private function createJobDefinitionsWithConfiguration(array $jobData, array $configuration): array
    {
        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decrypt')->with($configuration)->willReturn($configuration);

        $encryptorFactory = $this->createMock(ObjectEncryptorFactory::class);
        $encryptorFactory->method('getEncryptor')->willReturn($encryptor);

        $component = new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ]);

        $job = new Job($encryptorFactory, $jobData);

        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $factory = new JobDefinitionFactory();
        return $factory->createFromJob($component, $job, $storageApiClient);
    }
}
