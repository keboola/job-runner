<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
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
            $job->getBranchId(),
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
        ?string $branchId,
        ?string $jobVariableValuesId,
        array $variableValuesData,
    ): array {
        if ($jobVariableValuesId === '') {
            throw new InvalidArgumentException('$variableValuesId must not be empty string');
        }

        if ($branchId === null || $branchId === 'default') {
            $branchId = $this->resolveDefaultBranchId($clientWrapper);
        }

        if ($branchId === null || $branchId === '') {
            throw new ApplicationException('Can\'t resolve branchId for the job.');
        }

        $sharedCodeResolver = new SharedCodeResolver($clientWrapper, $this->logger);
        $variableResolver = VariablesResolver::create(
            $clientWrapper,
            $this->variablesApiClient,
            $this->logger,
        );

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
            ),
            $jobDefinitions,
        );
    }

    private function resolveDefaultBranchId(ClientWrapper $clientWrapper): ?string
    {
        $branchesApiClient = new DevBranches($clientWrapper->getBranchClientIfAvailable());
        foreach ($branchesApiClient->listBranches() as $branch) {
            if ($branch['isDefault']) {
                return (string) $branch['id'];
            }
        }

        return null;
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
                $jobDefinition->getState(),
                $jobDefinition->getRowId(),
                $jobDefinition->isDisabled(),
            ),
            $jobDefinitions,
        );
    }
}
