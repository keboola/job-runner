<?php

declare(strict_types=1);

namespace App;

use Keboola\ConfigurationVariablesResolver\SharedCodeResolver;
use Keboola\ConfigurationVariablesResolver\VariablesResolver;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptor\JobObjectEncryptor;
use Keboola\PermissionChecker\BranchType;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\VaultApiClient\Variables\VariablesApiClient;
use Psr\Log\LoggerInterface;

class JobDefinitionFactory
{
    public function __construct(
        private readonly JobDefinitionParser $jobDefinitionParser,
        private readonly JobObjectEncryptor $objectEncryptor,
        private readonly VariablesApiClient $variablesApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<JobDefinition>
     */
    public function createFromJob(
        Component $component,
        JobInterface $job,
        ClientWrapper $clientWrapper,
    ): array {
        $jobDefinitions = $this->createJobDefinitionsForJob(
            $clientWrapper,
            $component,
            $job,
        );

        $jobDefinitions = $this->resolveVariables(
            $clientWrapper,
            $jobDefinitions,
            $job->getVariableValuesId(),
            $job->getVariableValuesData(),
        );

        $jobDefinitions = $this->decryptConfiguration(
            $jobDefinitions,
            $job,
        );

        return $jobDefinitions;
    }

    /**
     * @return JobDefinition[]
     */
    public function createJobDefinitionsForJob(
        ClientWrapper $clientWrapper,
        Component $component,
        JobInterface $job,
    ): array {
        if ($component->blockBranchJobs() && $clientWrapper->isDevelopmentBranch()) {
            throw new UserException('This component cannot be run in a development branch.');
        }

        if ($component->getId() === 'keboola.sandboxes'
            && $clientWrapper->isDefaultBranch()
            && in_array(JobFactory::PROTECTED_DEFAULT_BRANCH_FEATURE, $job->getProjectFeatures(), true)
        ) {
            throw new UserException(sprintf(
                'Component "%s" is not allowed to run on %s branch.',
                $component->getId(),
                BranchType::DEFAULT->value,
            ));
        }

        if ($job->getConfigData()) {
            $configData = $job->getConfigData();
            $configData = $this->extendComponentConfigWithBackend($configData, $job);

            $jobDefinition = $this->jobDefinitionParser->parseConfigData(
                $component,
                $configData,
                $job->getConfigId(),
                $job->getBranchType()->value,
            );
            return [$jobDefinition];
        }

        try {
            $components = new Components($clientWrapper->getBranchClient());
            $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
            /** @var array $configuration */

            if (!$clientWrapper->getClientOptionsReadOnly()->useBranchStorage()) {
                $this->checkUnsafeConfiguration(
                    $component,
                    $configuration,
                    $job->getBranchType(),
                );
            }
        } catch (ClientException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        $configuration['configuration'] = $this->extendComponentConfigWithBackend(
            $configuration['configuration'] ?? [],
            $job,
        );

        return $this->jobDefinitionParser->parseConfig(
            $component,
            $configuration,
            $job->getBranchType()->value,
        );
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @return JobDefinition[]
     */
    private function resolveVariables(
        ClientWrapper $clientWrapper,
        array $jobDefinitions,
        ?string $jobVariableValuesId,
        array $variableValuesData,
    ): array {
        if ($jobVariableValuesId === '') {
            $jobVariableValuesId = null;
        }

        $sharedCodeResolver = new SharedCodeResolver($clientWrapper, $this->logger);
        $variableResolver = VariablesResolver::create(
            $clientWrapper,
            $this->variablesApiClient,
            $this->logger,
        );
        $branchId = $clientWrapper->getBranchId();
        assert(!empty($branchId));

        return array_map(
            function (JobDefinition $jobDefinition) use (
                $variableResolver,
                $sharedCodeResolver,
                $branchId,
                $jobVariableValuesId,
                $variableValuesData,
            ): JobDefinition {
                $resolveResults = $variableResolver->resolveVariables(
                    $sharedCodeResolver->resolveSharedCode(
                        $jobDefinition->getConfiguration(),
                    ),
                    $branchId,
                    $jobVariableValuesId,
                    $variableValuesData,
                );

                $jobDefinition = new JobDefinition(
                    $resolveResults->configuration,
                    $jobDefinition->getComponent(),
                    $jobDefinition->getConfigId(),
                    $jobDefinition->getConfigVersion(),
                    $jobDefinition->getState(),
                    $jobDefinition->getRowId(),
                    $jobDefinition->isDisabled(),
                    $jobDefinition->getBranchType(),
                    $resolveResults->replacedVariablesValues,
                );

                return $jobDefinition;
            },
            $jobDefinitions,
        );
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @return JobDefinition[]
     */
    private function decryptConfiguration(array $jobDefinitions, JobInterface $job): array
    {
        return array_map(
            fn(JobDefinition $jobDefinition) => new JobDefinition(
                $this->objectEncryptor->decrypt(
                    $jobDefinition->getConfiguration(),
                    $jobDefinition->getComponentId(),
                    $job->getProjectId(),
                    $jobDefinition->getConfigId(),
                    BranchType::from($jobDefinition->getBranchType()),
                ),
                $jobDefinition->getComponent(),
                $jobDefinition->getConfigId(),
                $jobDefinition->getConfigVersion(),
                $this->objectEncryptor->decrypt(
                    $jobDefinition->getState(),
                    $jobDefinition->getComponentId(),
                    $job->getProjectId(),
                    $jobDefinition->getConfigId(),
                    BranchType::from($jobDefinition->getBranchType()),
                ),
                $jobDefinition->getRowId(),
                $jobDefinition->isDisabled(),
                $jobDefinition->getBranchType(),
                $jobDefinition->getInputVariableValues(),
            ),
            $jobDefinitions,
        );
    }

    private function extendComponentConfigWithBackend(array $config, JobInterface $job): array
    {
        $backend = $job->getBackend();

        if ($backend->getType() !== null) {
            $config['runtime']['backend']['type'] = $backend->getType();
        }
        if ($backend->getContext() !== null) {
            $config['runtime']['backend']['context'] = $backend->getContext();
        }

        return $config;
    }

    private function checkUnsafeConfiguration(Component $component, array $configuration, BranchType $branchType): void
    {
        if ($component->branchConfigurationsAreUnsafe() && $branchType === BranchType::DEV) {
            if (empty($configuration['configuration']['runtime']['safe'])) {
                throw new UserException(
                    'It is not safe to run this configuration in a development branch. ' .
                    'Please review the configuration.',
                );
            }
        }
    }
}
