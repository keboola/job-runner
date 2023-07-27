<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\PermissionChecker\BranchType;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;

/**
 * TODO import Keboola\DockerBundle\Docker\JobDefinitionParser implementation inside here
 */
class JobDefinitionParser
{
    /**
     * @return JobDefinition[]
     */
    public function createJobDefinitionsForJob(
        ClientWrapper $clientWrapper,
        Component $component,
        JobInterface $job,
    ): array {
        if ($component->blockBranchJobs() && $clientWrapper->hasBranch()) {
            throw new UserException('This component cannot be run in a development branch.');
        }

        $jobDefinitionParser = new DockerBundleJobDefinitionParser();

        if ($job->getConfigData()) {
            $configData = $job->getConfigData();
            $configData = $this->extendComponentConfigWithBackend($configData, $job);

            $jobDefinition = $jobDefinitionParser->parseConfigData(
                $component,
                $configData,
                $job->getConfigId(),
                ($job->getBranchType() ?? BranchType::DEFAULT)->value,
            );
            return [$jobDefinition];
        }

        try {
            $components = new Components($clientWrapper->getBranchClientIfAvailable());
            $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
            /** @var array $configuration */

            if (!$clientWrapper->getClientOptionsReadOnly()->useBranchStorage()) {
                $this->checkUnsafeConfiguration(
                    $component,
                    $configuration,
                    $job->getBranchType() ?? BranchType::DEV
                );
            }
        } catch (ClientException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        $configuration['configuration'] = $this->extendComponentConfigWithBackend(
            $configuration['configuration'] ?? [],
            $job,
        );

        return $jobDefinitionParser->parseConfig(
            $component,
            $configuration,
            ($job->getBranchType() ?? BranchType::DEFAULT)->value,
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
                    'It is not safe to run this configuration in a development branch. Please review the configuration.'
                );
            }
        }
    }
}
