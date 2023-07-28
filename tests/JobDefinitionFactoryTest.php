<?php

declare(strict_types=1);

namespace App\Tests;

use App\DockerBundleJobDefinitionParser;
use App\JobDefinitionFactory;
use Generator;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptor\JobObjectEncryptor;
use Keboola\PermissionChecker\BranchType;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Keboola\VaultApiClient\Variables\VariablesApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JobDefinitionFactoryTest extends TestCase
{
    private function createComponent(array $features = []): Component
    {
        return new Component([
            'id' => 'my-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
            'features' => $features,
        ]);
    }

    private function createJob(array $jobData): Job
    {
        $encryptor = $this->createMock(JobObjectEncryptor::class);
        $encryptor->expects(self::never())->method(self::anything());

        $jobStorageClientFactory = $this->createMock(StorageClientPlainFactory::class);
        $jobStorageClientFactory->expects(self::never())->method(self::anything());

        return new Job($encryptor, $jobStorageClientFactory, $jobData);
    }

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
            'branchType' => null,
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfigData($jobData);

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
            'branchType' => null,
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfigData($jobData);
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
            'branchType' => null,
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
            'branchType' => null,
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
        $job = $this->createJob([
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'configId' => 'my-config',
            'branchType' => null,
        ]);

        $parserStorageApiClient = $this->createMock(Client::class);
        $parserStorageApiClient->method('apiGet')
            ->with('branch/default/components/my-component/configs/my-config')
            ->willThrowException(new ClientException(
                'Configuration my-config not found',
                404,
                null,
                'notFound'
            ))
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(false);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($parserStorageApiClient);

        $component = $this->createComponent();
        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Configuration my-config not found');
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
        );
    }

    /**
     * @return array<JobDefinition>
     */
    private function createJobDefinitionsWithConfigData(array $jobData): array
    {
        $component = $this->createComponent();
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->expects(self::never())->method(self::anything());

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(false);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );

        return $factory->createJobDefinitionsForJob($clientWrapper, $component, $job);
    }

    private function createJobDefinitionsWithConfiguration(array $jobData, array $configuration): array
    {
        $component = $this->createComponent();
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(Client::class);
        $storageApiClient->method('apiGet')
            ->with('branch/default/components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(false);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );

        return $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
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
            'branchId' => '123',
            'configId' => 'my-config',
            'branchType' => BranchType::DEV->value,
        ];

        $component = $this->createComponent();
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::once())->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(true);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );

        $jobDefinitions = $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
        );

        self::assertCount(1, $jobDefinitions);

        /** @var JobDefinition $jobDefinition */
        $jobDefinition = $jobDefinitions[0];

        self::assertSame($jobData['configId'], $jobDefinition->getConfigId());
        self::assertSame($jobData['componentId'], $jobDefinition->getComponentId());
        self::assertSame($jobData['branchType'], $jobDefinition->getBranchType());
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
            'branchType' => BranchType::DEV->value,
        ];

        $component = $this->createComponent(['dev-branch-configuration-unsafe']);
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::once())->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(true);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'It is not safe to run this configuration in a development branch. Please review the configuration.'
        );
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
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
            'branchId' => '123',
            'configId' => 'my-config',
            'branchType' => BranchType::DEV->value,
        ];

        $component = $this->createComponent(['dev-branch-configuration-unsafe']);
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::once())->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(true);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );
        $jobDefinitions = $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
        );

        self::assertCount(1, $jobDefinitions);
        $jobDefinition = $jobDefinitions[0];

        self::assertSame($jobData['configId'], $jobDefinition->getConfigId());
        self::assertSame($jobData['componentId'], $jobDefinition->getComponentId());
        self::assertSame('bar', $jobDefinition->getConfiguration()['runtime']['foo'] ?? null);
    }

    public function testCreateJobDefinitionBranchBlocked(): void
    {
        $jobData = [
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => 'my-component',
            'branchId' => 'my-branch',
            'configId' => 'my-config',
            'branchType' => BranchType::DEV->value,
        ];

        $component = $this->createComponent(['dev-branch-job-blocked']);
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::never())->method('apiGet');

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('hasBranch')->willReturn(true);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new DockerBundleJobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('This component cannot be run in a development branch.');
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
        );
    }
}
