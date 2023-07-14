<?php

declare(strict_types=1);

namespace App;

use Keboola\ConfigurationVariablesResolver\SharedCodeResolver;
use Keboola\ConfigurationVariablesResolver\VariablesResolver;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobFactory\ObjectEncryptor\JobObjectEncryptor;
use Keboola\PermissionChecker\BranchType;
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
        $jobDefinitions = $this->jobDefinitionParser->createJobDefinitionsForJob(
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
            fn(JobDefinition $jobDefinition) => new JobDefinition(
                $variableResolver->resolveVariables(
                    $sharedCodeResolver->resolveSharedCode(
                        $jobDefinition->getConfiguration(),
                    ),
                    $branchId,
                    $jobVariableValuesId,
                    $variableValuesData,
                ),
                $jobDefinition->getComponent(),
                $jobDefinition->getConfigId(),
                $jobDefinition->getConfigVersion(),
                $jobDefinition->getState(),
                $jobDefinition->getRowId(),
                $jobDefinition->isDisabled(),
                $jobDefinition->getBranchType(),
            ),
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
            ),
            $jobDefinitions,
        );
    }
}
