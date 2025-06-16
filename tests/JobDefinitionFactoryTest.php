<?php

declare(strict_types=1);

namespace App\Tests;

use App\JobDefinitionFactory;
use App\JobDefinitionParser;
use Generator;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobFactory\JobObjectEncryptor;
use Keboola\PermissionChecker\BranchType;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Keboola\VaultApiClient\Variables\VariablesApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JobDefinitionFactoryTest extends TestCase
{
    private function createComponent(
        array $features = [],
        string $componentId = 'my-component',
    ): ComponentSpecification {
        return new ComponentSpecification([
            'id' => $componentId,
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
            'branchType' => 'default',
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
        array $expectedBackendData,
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
            'branchType' => 'default',
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfigData($jobData);
        self::assertSame(
            $expectedBackendData,
            $jobDefinitions[0]->getConfiguration()['runtime']['backend'],
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
            'branchType' => 'default',
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
        ?array $expectedBackendData,
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
            'branchType' => 'default',
        ];

        $jobDefinitions = $this->createJobDefinitionsWithConfiguration($jobData, $configuration);

        if ($expectedBackendData !== null) {
            self::assertSame(
                $expectedBackendData,
                $jobDefinitions[0]->getConfiguration()['runtime']['backend'],
            );
        } else {
            self::assertArrayNotHasKey(
                'runtime',
                $jobDefinitions[0]->getConfiguration(),
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
            'branchType' => 'default',
        ]);

        $parserStorageApiClient = $this->createMock(BranchAwareClient::class);
        $parserStorageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willThrowException(new ClientException(
                'Configuration my-config not found',
                404,
                null,
                'notFound',
            ))
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(false);
        $clientWrapper->method('getBranchClient')->willReturn($parserStorageApiClient);

        $component = $this->createComponent();
        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Configuration my-config not found');
        $loggersServiceMock = $this->createMock(LoggersService::class);
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
        );
    }

    /**
     * @return array<JobDefinition>
     */
    private function createJobDefinitionsWithConfigData(array $jobData): array
    {
        $component = $this->createComponent();
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::never())->method(self::anything());

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(false);
        $clientWrapper->method('getBranchClient')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $loggersServiceMock = $this->createMock(LoggersService::class);
        return $factory->createJobDefinitionsForJob($clientWrapper, $component, $job, $loggersServiceMock);
    }

    private function createJobDefinitionsWithConfiguration(array $jobData, array $configuration): array
    {
        $component = $this->createComponent();
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(false);
        $clientWrapper->method('getBranchClient')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $loggersServiceMock = $this->createMock(LoggersService::class);
        return $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
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
        $clientWrapper->method('isDevelopmentBranch')->willReturn(true);
        $clientWrapper->method('getBranchClient')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $loggersServiceMock = $this->createMock(LoggersService::class);
        $jobDefinitions = $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
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

        $component = $this->createComponent(features: ['dev-branch-configuration-unsafe']);
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::once())->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(true);
        $clientWrapper->method('getBranchClient')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'It is not safe to run this configuration in a development branch. Please review the configuration.',
        );
        $loggersServiceMock = $this->createMock(LoggersService::class);
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
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

        $component = $this->createComponent(features: ['dev-branch-configuration-unsafe']);
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::once())->method('apiGet')
            ->with('components/my-component/configs/my-config')
            ->willReturn($configuration)
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(true);
        $clientWrapper->method('getBranchClient')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );
        $loggersServiceMock = $this->createMock(LoggersService::class);
        $jobDefinitions = $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
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

        $component = $this->createComponent(features: ['dev-branch-job-blocked']);
        $job = $this->createJob($jobData);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::never())->method('apiGet');

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(true);
        $clientWrapper->method('getBranchClient')->willReturn($storageApiClient);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('This component cannot be run in a development branch.');
        $loggersServiceMock = $this->createMock(LoggersService::class);
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
        );
    }

    public function testCreateJobDefinitionBlockedForSandboxesOnSoxDefaultBranch(): void
    {
        $componentId = 'keboola.sandboxes';
        $component = $this->createComponent(componentId: $componentId);

        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient
            ->expects(self::once())
            ->method('verifyToken')
            ->willReturn([
                'owner' => [
                    'features' => ['protected-default-branch'],
                ],
            ])
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper
            ->expects(self::once())
            ->method('isDefaultBranch')
            ->willReturn(true)
        ;
        $clientWrapper
            ->expects(self::once())
            ->method('getBranchClient')
            ->willReturn($storageApiClient)
        ;

        $encryptor = $this->createMock(JobObjectEncryptor::class);
        $encryptor
            ->expects(self::once())
            ->method('decrypt')
            ->with('encrypted-token', $componentId, 'my-project', null, BranchType::DEFAULT)
            ->willReturn('decrypted-token')
        ;

        $jobStorageClientFactory = $this->createMock(StorageClientPlainFactory::class);
        $jobStorageClientFactory->expects(self::once())
            ->method('createClientWrapper')
            ->willReturn($clientWrapper)
        ;

        $jobData = [
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => $componentId,
            'branchId' => 'my-branch',
            'configData' => [
                'parameters' => [],
            ],
            'branchType' => BranchType::DEFAULT->value,
            '#tokenString' => 'encrypted-token',
        ];

        $job = new Job($encryptor, $jobStorageClientFactory, $jobData);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Component "keboola.sandboxes" is not allowed to run on default branch.');
        $loggersServiceMock = $this->createMock(LoggersService::class);
        $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
        );
    }

    public function testCreateJobDefinitionIsNotBlockedForSandboxesOnDevBranch(): void
    {
        $storageApiClient = $this->createMock(BranchAwareClient::class);
        $storageApiClient->expects(self::never())->method('apiGet');

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper
            ->expects(self::once())
            ->method('isDefaultBranch')
            ->willReturn(false)
        ;

        $encryptor = $this->createMock(JobObjectEncryptor::class);
        $encryptor->expects(self::never())->method(self::anything());

        $jobStorageClientFactory = $this->createMock(StorageClientPlainFactory::class);
        $jobStorageClientFactory->expects(self::never())->method(self::anything());

        $componentId = 'keboola.sandboxes';
        $component = $this->createComponent(componentId: $componentId);

        $jobData = [
            'status' => JobInterface::STATUS_CREATED,
            'runId' => '1234',
            'projectId' => 'my-project',
            'componentId' => $componentId,
            'branchId' => 'my-branch',
            'configData' => [
                'parameters' => [],
            ],
            'branchType' => BranchType::DEFAULT->value,
            '#tokenString' => 'encrypted-token',
        ];

        $job = new Job($encryptor, $jobStorageClientFactory, $jobData);

        $factory = new JobDefinitionFactory(
            new JobDefinitionParser(),
            $this->createMock(JobObjectEncryptor::class),
            $this->createMock(VariablesApiClient::class),
            $this->createMock(LoggerInterface::class),
        );

        $loggersServiceMock = $this->createMock(LoggersService::class);
        $jobDefinitions = $factory->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
            $loggersServiceMock,
        );

        self::assertCount(1, $jobDefinitions);
    }
}
