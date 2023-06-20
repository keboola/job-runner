<?php

declare(strict_types=1);

namespace App;

use App\Helper\BranchTypeResolver;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;

class JobDefinitionFactory
{
    /**
     * @return array<JobDefinition>
     */
    public function createFromJob(
        Component $component,
        JobInterface $job,
        ObjectEncryptor $objectEncryptor,
        ClientWrapper $clientWrapper
    ): array {
        if ($component->blockBranchJobs() && $clientWrapper->hasBranch()) {
            throw new UserException('This component cannot be run in a development branch.');
        }

        $jobDefinitionParser = new JobDefinitionParser();

        if ($job->getConfigData()) {
            $configData = $job->getConfigDataDecrypted();
            $configData = $this->extendComponentConfigWithBackend($configData, $job);
            $jobDefinitionParser->parseConfigData(
                $component,
                $configData,
                $job->getConfigId(),
                BranchTypeResolver::resolveBranchType($job->getBranchId(), $clientWrapper),
            );
        } else {
            try {
                if ($clientWrapper->hasBranch()) {
                    $components = new Components($clientWrapper->getBranchClient());
                    $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
                } else {
                    $components = new Components($clientWrapper->getBasicClient());
                    $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
                }

                /** @var array $configuration */

                $this->checkUnsafeConfiguration(
                    $component,
                    $configuration,
                    (string) $clientWrapper->getBranchId()
                );
            } catch (ClientException $e) {
                throw new UserException($e->getMessage(), $e);
            }

            $configuration = $objectEncryptor->decryptForConfiguration(
                $configuration,
                $job->getComponentId(),
                $job->getProjectId(),
                (string) $job->getConfigId(),
            );

            $configuration['configuration'] = $this->extendComponentConfigWithBackend(
                $configuration['configuration'] ?? [],
                $job
            );

            $jobDefinitionParser->parseConfig(
                $component,
                $configuration,
                BranchTypeResolver::resolveBranchType($job->getBranchId(), $clientWrapper),
            );
        }

        return $jobDefinitionParser->getJobDefinitions();
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

    private function checkUnsafeConfiguration(Component $component, array $configuration, string $branchId): void
    {
        if ($component->branchConfigurationsAreUnsafe() && $branchId) {
            if (empty($configuration['configuration']['runtime']['safe'])) {
                throw new UserException(
                    'It is not safe to run this configuration in a development branch. Please review the configuration.'
                );
            }
        }
    }
}
