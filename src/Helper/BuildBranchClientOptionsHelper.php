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
        /*
        Here we intentionally set runId to jobId (setRunId($job->getId())) because it no longer holds that
        storage-api-run-id is the same as job-queue-run-id. storage-api-run-id now serves as a tracing id
        attributing storage operations to job. job-queue-run-id describes hierarchical structure of jobs.
        By using jobId as storage runId we're attributing storage operations to that job (and not its parents).
        Technically the reason is that storage api run id has a limited length and cannot be easily extended.
        */
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
