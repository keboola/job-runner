<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\StorageApi\Client as StorageClient;
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
            $jobDefinitionParser->parseConfigData($component, $configData, $job->getConfigId());
        } else {
            $components = new Components($client);
            $configuration = $components->getConfiguration($job->getComponentId(), $job->getConfigId());
            $configuration = $job->getEncryptorFactory()->getEncryptor()->decrypt($configuration);
            $jobDefinitionParser->parseConfig($component, $configuration);
        }

        return $jobDefinitionParser->getJobDefinitions();
    }
}
