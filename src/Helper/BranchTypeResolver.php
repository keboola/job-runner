<?php

declare(strict_types=1);

namespace App\Helper;

use Closure;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\BackendConfiguration;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;

class BranchTypeResolver
{
    /**
     * @return ObjectEncryptor::BRANCH_TYPE_DEFAULT|ObjectEncryptor::BRANCH_TYPE_DEV
     */
    public static function resolveBranchType(?string $branchId, ClientWrapper $clientWrapper): string
    {
        if ($branchId === 'default' || $branchId === null) {
            return ObjectEncryptor::BRANCH_TYPE_DEFAULT;
        }
        $branches = new DevBranches($clientWrapper->getBasicClient());
        $branch = $branches->getBranch((int) $branchId);
        return $branch['isDefault'] ? ObjectEncryptor::BRANCH_TYPE_DEFAULT : ObjectEncryptor::BRANCH_TYPE_DEV;
    }
}
