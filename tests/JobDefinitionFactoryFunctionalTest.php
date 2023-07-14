<?php

declare(strict_types=1);

namespace App\Tests;

use App\JobDefinitionFactory;
use Keboola\ConfigurationVariablesResolver\ComponentsClientHelper;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptor\JobObjectEncryptor;
use Keboola\PermissionChecker\BranchType;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\Configuration as StorageConfiguration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Keboola\VaultApiClient\Variables\Model\ListOptions;
use Keboola\VaultApiClient\Variables\VariablesApiClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JobDefinitionFactoryFunctionalTest extends KernelTestCase
{
    use TestEnvVarsTrait;

    private const TEST_NAME = 'job-definition-factory-test';

    private const VARIABLES_CONFIG_ID = self::TEST_NAME;
    private const VARIABLES_ROW_ID = self::TEST_NAME;

    private const SHARED_CODE_CONFIG_ID = self::TEST_NAME;
    private const SHARED_CODE_ROW_ID = self::TEST_NAME;

    private readonly JobObjectEncryptor $jobObjectEncryptor;
    private readonly ClientWrapper $clientWrapper;
    private readonly Component $component;
    private readonly JobDefinitionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobObjectEncryptor = static::getContainer()->get(JobObjectEncryptor::class);

        $storageClientFactory = static::getContainer()->get(StorageClientPlainFactory::class);
        $this->clientWrapper = $storageClientFactory->createClientWrapper(new ClientOptions(
            token: self::getRequiredEnv('STORAGE_API_TOKEN'),
        ));

        $this->component = new Component([
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/runner-config-test',
                ],
            ],
        ]);

        $this->factory = static::getContainer()->get(JobDefinitionFactory::class);
    }

    public function testCreateWithConfigData(): void
    {
        $this->setupConfigurationVariables(
            [
                ['name' => 'var', 'type' => 'string'],
            ],
            [
                ['name' => 'var', 'value' => 'val'],
            ],
        );

        $job = $this->createJob([
            'configData' => [
                'variables_id' => self::VARIABLES_CONFIG_ID,
                'variables_values_id' => self::VARIABLES_ROW_ID,
                'parameters' => [
                    'script' => 'print("config var: {{ var }}")',
                ],
            ],
        ]);

        $jobDefinitions = $this->factory->createFromJob(
            $this->component,
            $job,
            $this->clientWrapper,
        );
        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertInstanceOf(JobDefinition::class, $jobDefinition);
        self::assertSame(
            'print("config var: val")',
            $jobDefinition->getConfiguration()['parameters']['script'] ?? null,
        );
    }

    public function testCreateWithBranch(): void
    {
        $this->setupConfigurationVariables(
            [
                ['name' => 'var', 'type' => 'string'],
            ],
            [
                ['name' => 'var', 'value' => 'val'],
            ],
        );

        $job = $this->createJob(
            [
                'configData' => [
                    'variables_id' => self::VARIABLES_CONFIG_ID,
                    'variables_values_id' => self::VARIABLES_ROW_ID,
                    'parameters' => [
                        'script' => 'print("config var: {{ var }}")',
                    ],
                ],
            ],
            'dev',
        );

        $jobDefinitions = $this->factory->createFromJob(
            $this->component,
            $job,
            $this->clientWrapper,
        );
        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertInstanceOf(JobDefinition::class, $jobDefinition);
        self::assertSame('dev', $jobDefinition->getBranchType());
        self::assertSame(
            'print("config var: val")',
            $jobDefinition->getConfiguration()['parameters']['script'] ?? null,
        );
    }

    public function testVariablesAreReplacedFromConfigAndVault(): void
    {
        $job = $this->createJob([
            'configData' => [
                'variables_id' => self::VARIABLES_CONFIG_ID,
                'variables_values_id' => self::VARIABLES_ROW_ID,
                'parameters' => [
                    'script' => 'print("config var: {{ var1 }}, vault var: {{ vault.var1 }}")',
                ],
            ],
        ]);

        $this->setupConfigurationVariables(
            [
                ['name' => 'var1', 'type' => 'string'],
            ],
            [
                ['name' => 'var1', 'value' => 'config val'],
            ],
        );

        $this->setupVaultVariables([
            'var1' => 'vault val',
        ]);

        $jobDefinitions = $this->factory->createFromJob(
            $this->component,
            $job,
            $this->clientWrapper,
        );
        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertInstanceOf(JobDefinition::class, $jobDefinition);
        self::assertSame(
            'print("config var: config val, vault var: vault val")',
            $jobDefinition->getConfiguration()['parameters']['script'] ?? null,
        );
    }

    public function testVariablesAreReplacedInSharedCodes(): void
    {
        $sharedCodeConfigId = self::TEST_NAME;

        $job = $this->createJob([
            'configData' => [
                'variables_id' => self::VARIABLES_CONFIG_ID,
                'variables_values_id' => self::VARIABLES_ROW_ID,
                'shared_code_id' => $sharedCodeConfigId,
                'shared_code_row_ids' => ['code1'],
                'parameters' => [
                    'script' => ['Shared {{ code1 }}'],
                ],
            ],
        ]);

        $this->setupSharedCode($sharedCodeConfigId, [
            'code1' => 'print("shared code var: {{ var1 }} and {{ vault.var1 }}")',
        ]);

        $this->setupConfigurationVariables(
            [
                ['name' => 'var1', 'type' => 'string'],
            ],
            [
                ['name' => 'var1', 'value' => 'config val'],
            ],
        );

        $this->setupVaultVariables([
            'var1' => 'vault val',
        ]);

        $jobDefinitions = $this->factory->createFromJob(
            $this->component,
            $job,
            $this->clientWrapper,
        );
        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertInstanceOf(JobDefinition::class, $jobDefinition);
        self::assertSame(
            ['print("shared code var: config val and vault val")'],
            $jobDefinition->getConfiguration()['parameters']['script'] ?? null,
        );
    }

    public function testVariablesAreReplacedBeforeDecrypted(): void
    {
        $job = $this->createJob([
            'configData' => [
                'variables_id' => self::VARIABLES_CONFIG_ID,
                'variables_values_id' => self::VARIABLES_ROW_ID,
                'parameters' => [
                    'script' => 'print("config var: {{ var1 }}")',
                    '#password' => '{{ var2 }}',
                ],
            ],
        ]);

        $encryptedValue = $this->jobObjectEncryptor->encrypt(
            'val2',
            $job->getComponentId(),
            $job->getProjectId(),
            $job->getBranchType(),
        );

        $this->setupConfigurationVariables(
            [
                ['name' => 'var1', 'type' => 'string'],
                ['name' => 'var2', 'type' => 'string'],
            ],
            [
                ['name' => 'var1', 'value' => 'val1'],
                ['name' => 'var2', 'value' => $encryptedValue],
            ],
        );

        $jobDefinitions = $this->factory->createFromJob(
            $this->component,
            $job,
            $this->clientWrapper,
        );
        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertInstanceOf(JobDefinition::class, $jobDefinition);
        self::assertSame(
            'print("config var: val1")',
            $jobDefinition->getConfiguration()['parameters']['script'] ?? null,
        );
        self::assertSame(
            'val2',
            $jobDefinition->getConfiguration()['parameters']['#password'] ?? null,
        );
    }

    private function createJob(array $jobData, string $branchType = null): Job
    {
        return new Job(
            static::getContainer()->get(JobObjectEncryptor::class),
            static::getContainer()->get(StorageClientPlainFactory::class),
            [
                'id' => '1234',
                'runId' => '1234',
                'componentId' => $this->component->getId(),
                'projectId' => explode('-', self::getRequiredEnv('STORAGE_API_TOKEN'), 2)[1],
                'status' => JobInterface::STATUS_CREATED,
                'branchType' => $branchType ?? BranchType::DEFAULT->value,
                ...$jobData,
            ],
        );
    }

    private function setupConfigurationVariables(array $variablesDefinition, array $variablesValues): void
    {
        $configuration = new StorageConfiguration();
        $configuration->setConfigurationId(self::VARIABLES_CONFIG_ID);
        $configuration->setName(self::VARIABLES_CONFIG_ID);
        $configuration->setComponentId(ComponentsClientHelper::KEBOOLA_VARIABLES);
        $configuration->setConfiguration(['variables' => $variablesDefinition]);

        $row = new ConfigurationRow($configuration);
        $row->setRowId(self::VARIABLES_ROW_ID);
        $row->setName(self::VARIABLES_ROW_ID);
        $row->setConfiguration(['values' => $variablesValues]);

        $this->setupConfigurationWithRows($configuration, [$row]);
    }

    /**
     * @param array<non-empty-string, string> $sharedCodes
     */
    private function setupSharedCode(string $sharedCodeConfigId, array $sharedCodes): void
    {
        $configuration = new StorageConfiguration();
        $configuration->setConfigurationId($sharedCodeConfigId);
        $configuration->setName(self::SHARED_CODE_CONFIG_ID);
        $configuration->setComponentId(ComponentsClientHelper::KEBOOLA_SHARED_CODE);
        $configuration->setConfiguration([
            'componentId' => 'keboola.python-transformation-v2',
        ]);

        $rows = [];
        foreach ($sharedCodes as $codeId => $codeContent) {
            $row = new ConfigurationRow($configuration);
            $row->setRowId($codeId);
            $row->setName(self::SHARED_CODE_ROW_ID);
            $row->setConfiguration([
                'code_content' => $codeContent,
            ]);

            $rows[] = $row;
        }

        $this->setupConfigurationWithRows($configuration, $rows);
    }

    private function setupConfigurationWithRows(StorageConfiguration $configuration, array $rows): void
    {
        $componentsApiClient = new Components($this->clientWrapper->getBranchClientIfAvailable());

        try {
            $componentsApiClient->deleteConfiguration(
                $configuration->getComponentId(),
                $configuration->getConfigurationId(),
            );
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $componentsApiClient->addConfiguration($configuration);

        foreach ($rows as $row) {
            $componentsApiClient->addConfigurationRow($row);
        }
    }

    /**
     * @param array<non-empty-string, string> $values
     */
    private function setupVaultVariables(array $values): void
    {
        $attributes = [
            'testId' => 'job-runner-variables-test',
        ];

        $vaultVariablesClient = static::getContainer()->get(VariablesApiClient::class);
        $existingVariables = $vaultVariablesClient->listVariables(new ListOptions(attributes: $attributes));

        foreach ($existingVariables as $variable) {
            $vaultVariablesClient->deleteVariable($variable->hash);
        }

        foreach ($values as $key => $value) {
            $vaultVariablesClient->createVariable($key, $value, attributes: $attributes);
        }
    }
}
