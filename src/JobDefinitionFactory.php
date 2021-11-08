<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;

class JobDefinitionFactory
{
    /**
     * @return array<JobDefinition>
     */
    public function createFromJob(Component $component, JobInterface $job, StorageClient $client): array
    {
        $jobDefinitionParser = new JobDefinitionParser();

        if ($job->getConfigData()) {
            $configData = $job->getConfigDataDecrypted();
            $configData = $this->extendComponentConfigWithBackend($configData, $job);
            $jobDefinitionParser->parseConfigData($component, $configData, $job->getConfigId());
        } else {
            $components = new Components($client);
            try {
                $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
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
}
