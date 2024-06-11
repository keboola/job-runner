<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\ObjectEncryptor\ObjectEncryptor;

class JobDefinitionParser
{
    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function parseConfigData(
        Component $component,
        array $configData,
        ?string $configId,
        string $branchType,
    ): JobDefinition {
        return new JobDefinition(
            configuration: $configData,
            component: $component,
            configId: $configId,
            branchType: $branchType,
        );
    }

    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     * @return JobDefinition[]
     */
    public function parseConfig(
        Component $component,
        array $config,
        string $branchType,
        LoggersService $loggersService,
        array $rowIds = [],
    ): array {
        $config['rows'] = $config['rows'] ?? [];
        $this->validateConfig($config);

        if (count($config['rows']) === 0) {
            $jobDefinition = new JobDefinition(
                configuration: $config['configuration'] ? (array) $config['configuration'] : [],
                component: $component,
                configId: (string) $config['id'],
                configVersion: (string) $config['version'],
                state: $config['state'] ? (array) $config['state'] : [],
                branchType: $branchType,
            );
            return [$jobDefinition];
        }

        if ($rowIds) {
            $config['rows'] = array_filter(
                $config['rows'],
                fn(array $row) => in_array($row['id'], $rowIds, true),
            );

            if (count($config['rows']) === 0) {
                throw new UserException(sprintf('None of rows "%s" was found.', implode(',', $rowIds)));
            }
        } else {
            $rowsToBeDisabled = array_filter(
                $config['rows'],
                fn(array $row) => $row['isDisabled'],
            );

            foreach ($rowsToBeDisabled as $row) {
                $loggersService->getLog()->info(
                    'Skipping disabled configuration: ' . $config['id']
                    . ', version: ' . $config['version']
                    . ', row: ' . $row['id'],
                );
            }

            assert($rowsToBeDisabled !== null);
            $config['rows'] = array_diff_key($config['rows'], $rowsToBeDisabled);
        }

        return array_map(
            fn (array $row) => new JobDefinition(
                array_replace_recursive($config['configuration'], $row['configuration']),
                $component,
                (string) $config['id'],
                (string) $config['version'],
                $row['state'] ? (array) $row['state'] : [],
                (string) $row['id'],
                (bool) $row['isDisabled'],
                $branchType,
            ),
            $config['rows'],
        );
    }

    private function validateConfig(array $config): void
    {
        $hasProcessors = !empty($config['configuration']['processors']['before'])
            || !empty($config['configuration']['processors']['after']);
        $hasRowProcessors = $this->hasRowProcessors($config);
        if ($hasProcessors && $hasRowProcessors) {
            throw new UserException(
                'Processors may be set either in configuration or in configuration row, but not in both places.',
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
}
