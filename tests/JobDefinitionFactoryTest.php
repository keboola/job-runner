<?php

declare(strict_types=1);

namespace App\Tests;

use App\JobDefinitionFactory;
use Generator;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptor\JobObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
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

    public function createJobDefinitionWithConfigDataAndBackendData(): Generator
    {
        yield 'nothing' => [
            [],
            [
                'type' => 'invalid',
                'context' => 'wml-invalid',
            ],
        ];
        yield 'type' => [
            [
                'type' => 'custom',
            ],
            [
                'type' => 'custom',
                'context' => 'wml-invalid',
            ],
        ];
        yield 'context' => [
            [
                'context' => 'wlm',
            ],
            [
                'type' => 'invalid',
                'context' => 'wlm',
            ],
        ];
        yield 'type + context' => [
            [
                'type' => 'custom',
                'context' => 'wlm',
            ],
            [
                'type' => 'custom',
                'context' => 'wlm',
            ],
        ];
    }

    /**
     * @dataProvider createJobDefinitionWithConfigDataAndBackendData
     */
    public function testCreateJobDefinitionWithConfigDataAndBackend(
        array $backendData,
        array $expectedBackendData
    ): void {
        $configData = [
            'runtime' => [
                'foo' => 'bar',
                'backend' => [
                    'type' => 'invalid',
                    'context' => 'wml-invalid',
                ],
            ],
        ];

        $jobData = [
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
            'configData' => $configData,
            'backend' => $backendData,
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfigData($jobData, $configData);
        self::assertSame(
            $expectedBackendData,
            $jobDefinitions[0]->getConfiguration()['runtime']['backend']
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
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

    public function createJobDefinitionWithConfigIdAndBackendData(): Generator
    {
        yield 'nothing' => [
            [],
            null,
        ];
        yield 'type' => [
            [
                'type' => 'custom',
            ],
            [
                'type' => 'custom',
            ],
        ];
        yield 'context' => [
            [
                'context' => 'wlm',
            ],
            [
                'context' => 'wlm',
            ],
        ];
        yield 'type + context' => [
            [
                'type' => 'custom',
                'context' => 'wlm',
            ],
            [
                'type' => 'custom',
                'context' => 'wlm',
            ],
        ];
    }

    /**
     * @dataProvider createJobDefinitionWithConfigIdAndBackendData
     */
    public function testCreateJobDefinitionWithConfigIdAndBackend(
        array $backendData,
        ?array $expectedBackendData
    ): void {
        $configuration = [
            'id' => 'my-config',
            'version' => '1',
            'state' => [],
            'rows' => [],
            'runtime' => [
                'foo' => 'bar',
                'backend' => [
                    'type' => 'invalid',
                    'context' => 'wml-invalid',
                ],
            ],
        ];

        $jobData = [
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
            'backend' => $backendData,
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfiguration($jobData, $configuration);

        if ($expectedBackendData !== null) {
            self::assertSame(
                $expectedBackendData,
                $jobDefinitions[0]->getConfiguration()['runtime']['backend']
            );
        } else {
            self::assertArrayNotHasKey(
                'runtime',
                $jobDefinitions[0]->getConfiguration()
            );
        }
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
        ];

        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decryptForConfiguration')->with($configuration)->willReturn($configuration);

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );
        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->method('apiGet')
            ->with('branch/default/components/my-component/configs/my-config')
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

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Configuration my-config not found');
        $factory->createFromJob(
            $component,
            $job,
            $encryptor,
            $this->getStorageApiClientMock($storageApiClient),
        );
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
        $encryptor->method('decryptForConfiguration')->with($configData)->willReturn($configData);

        $component = new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ]);

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );

        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->expects(self::never())->method(self::anything());

        $factory = new JobDefinitionFactory();
        return $factory->createFromJob(
            $component,
            $job,
            $encryptor,
            $this->getStorageApiClientMock($storageApiClient),
        );
    }

    private function createJobDefinitionsWithConfiguration(array $jobData, array $configuration): array
    {
        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decryptForConfiguration')->with($configuration)->willReturn($configuration);

        $component = new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ]);

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );

        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->method('apiGet')
            ->with('branch/default/components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $factory = new JobDefinitionFactory();
        return $factory->createFromJob(
            $component,
            $job,
            $encryptor,
            $this->getStorageApiClientMock($storageApiClient),
        );
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
        $encryptor->method('decryptForConfiguration')->with($configuration)->willReturn($configuration);

        $component = new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ]);

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration);

        $factory = new JobDefinitionFactory();

        return $factory->createFromJob(
            $component,
            $job,
            $encryptor,
            $this->getStorageApiClientBranchMock($storageApiClient)
        );
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
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
        self::assertSame('bar', $jobDefinition->getConfiguration()['runtime']['foo']);
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decryptForConfiguration')->with($configuration)->willReturn($configuration);

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

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );

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
            'It is not safe to run this configuration in a development branch. Please review the configuration.'
        );
        $factory->createFromJob(
            $component,
            $job,
            $encryptor,
            $this->getStorageApiClientBranchMock($storageApiClient),
        );
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decryptForConfiguration')->with($configuration)->willReturn($configuration);

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

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );

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
            $encryptor,
            $this->getStorageApiClientBranchMock($storageApiClient),
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
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'branchId' => 'my-branch',
            'configId' => 'my-config',
        ];

        $encryptor = $this->createMock(ObjectEncryptor::class);
        $encryptor->method('decryptForConfiguration')->with($configuration)->willReturn($configuration);

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

        $job = new Job(
            new JobObjectEncryptor($encryptor),
            $this->createMock(StorageClientPlainFactory::class),
            $jobData
        );

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
        $factory->createFromJob(
            $component,
            $job,
            $encryptor,
            $this->getStorageApiClientBranchMock($storageApiClient),
        );
    }
}
