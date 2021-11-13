<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;

class JobDefinitionFactory
{
    /**
     * @return array<JobDefinition>
     */
    public function createFromJob(Component $component, JobInterface $job, ClientWrapper $clientWrapper): array
    {
        if ($component->blockBranchJobs() && $clientWrapper->hasBranch()) {
            throw new UserException('This component cannot be run in a development branch.');
        }

        $jobDefinitionParser = new JobDefinitionParser();

        if ($job->getConfigData()) {
            $configData = $job->getConfigDataDecrypted();
            $configData = $this->extendComponentConfigWithBackend($configData, $job);
            $jobDefinitionParser->parseConfigData($component, $configData, $job->getConfigId());
        } else {
            try {
                if ($clientWrapper->hasBranch()) {
                    $components = new Components($clientWrapper->getBranchClient());
                    $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
                } else {
                    $components = new Components($clientWrapper->getBasicClient());
                    $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
                }

                $this->checkUnsafeConfiguration(
                    $component,
                    $configuration,
                    (string) $clientWrapper->getBranchId()
                );
            } catch (ClientException $e) {
                throw new UserException($e->getMessage(), $e);
            }

            $configuration = $job->getEncryptorFactory()->getEncryptor()->decrypt($configuration);
            $configuration['configuration'] = $this->extendComponentConfigWithBackend(
                $configuration['configuration'] ?? [],
                $job
            );

            $jobDefinitionParser->parseConfig($component, $configuration);
        }

        return $jobDefinitionParser->getJobDefinitions();
    }

    private function extendComponentConfigWithBackend(array $config, JobInterface $job): array
    {
        $backend = $job->getBackend();
        if ($backend->getType() === null) {
            return $config;
        }

        $config['runtime']['backend'] = [
            'type' => $backend->getType(),
        ];

        return $config;
    }

    private function checkUnsafeConfiguration(Component $component, array $configuration, string $branchId): void
    {
        if ($component->branchConfigurationsAreUnsafe() && $branchId) {
            if (empty($configuration['configuration']['runtime']['safe'])) {
                throw new UserException(
                    'Is is not safe to run this configuration in a development branch. Please review the configuration.'
                );
            }
        }
    }
}
