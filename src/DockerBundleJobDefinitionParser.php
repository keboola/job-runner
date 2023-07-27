<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\ObjectEncryptor\ObjectEncryptor;

class DockerBundleJobDefinitionParser
{
    /**
     * @var JobDefinition[]
     */
    private array $jobDefinitions = [];

    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function parseConfigData(
        Component $component,
        array $configData,
        ?string $configId,
        string $branchType,
    ): void {
        $jobDefinition = new JobDefinition(
            configuration: $configData,
            component: $component,
            configId: $configId,
            branchType: $branchType,
        );
        $this->jobDefinitions = [$jobDefinition];
    }

    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function parseConfig(
        Component $component,
        array $config,
        string $branchType,
    ): void {
        $config['rows'] = $config['rows'] ?? [];
        $this->validateConfig($config);
        $this->jobDefinitions = [];
        if (count($config['rows']) === 0) {
            $jobDefinition = new JobDefinition(
                configuration: $config['configuration'] ? (array) $config['configuration'] : [],
                component: $component,
                configId: (string) $config['id'],
                configVersion: (string) $config['version'],
                state: $config['state'] ? (array) $config['state'] : [],
                branchType: $branchType
            );
            $this->jobDefinitions[] = $jobDefinition;
        } else {
            foreach ($config['rows'] as $row) {
                $jobDefinition = new JobDefinition(
                    array_replace_recursive($config['configuration'], $row['configuration']),
                    $component,
                    (string) $config['id'],
                    (string) $config['version'],
                    $row['state'] ? (array) $row['state'] : [],
                    (string) $row['id'],
                    (bool) $row['isDisabled'],
                    $branchType
                );
                $this->jobDefinitions[] = $jobDefinition;
            }
        }
    }

    private function validateConfig(array $config): void
    {
        $hasProcessors = !empty($config['configuration']['processors']['before'])
            || !empty($config['configuration']['processors']['after']);
        $hasRowProcessors = $this->hasRowProcessors($config);
        if ($hasProcessors && $hasRowProcessors) {
            throw new UserException(
                'Processors may be set either in configuration or in configuration row, but not in both places.'
            );
        }
    }

    private function hasRowProcessors(array $config): bool
    {
        foreach ($config['rows'] as $row) {
            if (!empty($row['configuration']['processors']['before'])
                || !empty($row['configuration']['processors']['after'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return JobDefinition[]
     */
    public function getJobDefinitions(): array
    {
        return $this->jobDefinitions;
    }
}
