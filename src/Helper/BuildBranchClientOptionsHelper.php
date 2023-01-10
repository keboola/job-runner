<?php

declare(strict_types=1);

namespace App\Helper;

use Closure;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\StorageApi\Options\BackendConfiguration;
use Keboola\StorageApiBranch\Factory\ClientOptions;

class BuildBranchClientOptionsHelper
{
    public static function buildFromJob(JobInterface $job): ClientOptions
    {
        return (new ClientOptions())
            ->setUserAgent($job->getComponentId())
            ->setBranchId($job->getBranchId())
            ->setRunId($job->getId())
            ->setJobPollRetryDelay(self::getStepPollDelayFunction())
            ->setBackendConfiguration(new BackendConfiguration(
                $job->getBackend()->getContext(),
                $job->getBackend()->getType()
            ))
        ;
    }

    private static function getStepPollDelayFunction(): Closure
    {
        return function ($tries) {
            switch (true) {
                case ($tries < 15):
                    return 1;
                case ($tries < 30):
                    return 2;
                default:
                    return 5;
            }
        };
    }
}
