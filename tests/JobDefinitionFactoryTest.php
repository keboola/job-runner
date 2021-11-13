<?php

declare(strict_types=1);

namespace App\Tests;

use App\JobDefinitionFactory;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
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

    public function testCreateJobDefinitionWithConfigNotFound(): void
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

        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decrypt')->with($configuration)->willReturn($configuration);

        $encryptorFactory = $this->createMock(ObjectEncryptorFactory::class);
        $encryptorFactory->method('getEncryptor')->willReturn($encryptor);

        $job = new Job($encryptorFactory, $jobData);
        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willThrowException(new ClientException(
                'Configuration my-config not found',
                404,
                null,
                'notFound'
            ))
        ;

        $component = new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ]);
        $factory = new JobDefinitionFactory();

        self::expectException(UserException::class);
        self::expectExceptionMessage('Configuration my-config not found');
        $factory->createFromJob($component, $job, $this->getStorageApiClientMock($storageApiClient));
    }

    private function getStorageApiClientMock(Client $basicClient): ClientWrapper
    {
        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($basicClient);
        $clientWrapperMock->method('hasBranch')->willReturn(false);
        $clientWrapperMock->expects(self::never())->method('getBranchClient');
        return $clientWrapperMock;
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
        return $factory->createFromJob($component, $job, $this->getStorageApiClientMock($storageApiClient));
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
        return $factory->createFromJob($component, $job, $this->getStorageApiClientMock($storageApiClient));
    }

    private function getStorageApiClientBranchMock(Client $branchClient): ClientWrapper
    {
        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->expects(self::never())->method('getBasicClient');
        $clientWrapperMock->method('hasBranch')->willReturn(true);
        $clientWrapperMock->method('getBranchId')->willReturn('my-branch');
        $clientWrapperMock->method('getBranchClient')->willReturn($branchClient);
        return $clientWrapperMock;
    }

    private function createJobDefinitionsWithBranchConfiguration(array $jobData, array $configuration): array
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

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration);

        $factory = new JobDefinitionFactory();
        return $factory->createFromJob($component, $job, $this->getStorageApiClientBranchMock($storageApiClient));
    }

    public function testCreateJobDefinitionWithBranchConfigId(): void
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
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

        $jobDefinitions = $this->createJobDefinitionsWithBranchConfiguration($jobData, $configuration);

        self::assertCount(1, $jobDefinitions);
        $jobDefinition = $jobDefinitions[0];

        self::assertSame($jobData['configId'], $jobDefinition->getConfigId());
        self::assertSame($jobData['componentId'], $jobDefinition->getComponentId());
        self::assertSame('bar', $jobDefinition->getConfiguration()['runtime']['foo'] ?? null);
    }

    public function testCreateJobDefinitionBranchUnsafe(): void
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
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

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
            'features' => ['dev-branch-configuration-unsafe'],
        ]);

        $job = new Job($encryptorFactory, $jobData);

        $storageApiClient = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs(
                [
                    'my-branch',
                    ['token' => '123', 'url' => 'https://connection.keboola.com'],
                ]
            )->getMock();
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration);

        $factory = new JobDefinitionFactory();
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Is is not safe to run this configuration in a development branch. Please review the configuration.'
        );
        $factory->createFromJob($component, $job, $this->getStorageApiClientBranchMock($storageApiClient));
    }

    public function testCreateJobDefinitionBranchUnsafeSafe(): void
    {
        $configuration = [
            'id' => 'my-config',
            'version' => '1',
            'state' => [],
            'rows' => [],
            'configuration' => [
                'runtime' => [
                    'foo' => 'bar',
                    'safe' => true,
                ],
            ],
        ];

        $jobData = [
            'status' => JobFactory::STATUS_CREATED,
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

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
            'features' => ['dev-branch-configuration-unsafe'],
        ]);

        $job = new Job($encryptorFactory, $jobData);

        $storageApiClient = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs(
                [
                    'my-branch',
                    ['token' => '123', 'url' => 'https://connection.keboola.com'],
                ]
            )->getMock();
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration);

        $factory = new JobDefinitionFactory();
        $jobDefinitions = $factory->createFromJob(
            $component,
            $job,
            $this->getStorageApiClientBranchMock($storageApiClient)
        );

        self::assertCount(1, $jobDefinitions);
        $jobDefinition = $jobDefinitions[0];

        self::assertSame($jobData['configId'], $jobDefinition->getConfigId());
        self::assertSame($jobData['componentId'], $jobDefinition->getComponentId());
        self::assertSame('bar', $jobDefinition->getConfiguration()['runtime']['foo'] ?? null);
    }

    public function testCreateJobDefinitionBranchBlocked(): void
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
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

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
            'features' => ['dev-branch-job-blocked'],
        ]);

        $job = new Job($encryptorFactory, $jobData);

        $storageApiClient = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs(
                [
                    'my-branch',
                    ['token' => '123', 'url' => 'https://connection.keboola.com'],
                ]
            )->getMock();
        $storageApiClient->expects(self::never())->method('apiGet');
        $factory = new JobDefinitionFactory();
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('This component cannot be run in a development branch.');
        $factory->createFromJob($component, $job, $this->getStorageApiClientBranchMock($storageApiClient));
    }
}
