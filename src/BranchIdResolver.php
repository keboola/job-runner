<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApiBranch\ClientWrapper;

class BranchIdResolver
{
    /**
     * @return non-empty-string
     */
    public function resolveBranchId(ClientWrapper $clientWrapper, ?string $branchId): string
    {
        if ($branchId === null || $branchId === 'default') {
            $branchesApiClient = new DevBranches($clientWrapper->getBasicClient());
            foreach ($branchesApiClient->listBranches() as $branch) {
                if ($branch['isDefault']) {
                    $branchId = (string) $branch['id'];
                    break;
                }
            }
        }

        if ($branchId === null || $branchId === 'default' || $branchId === '') {
            throw new ApplicationException('Can\'t resolve branchId for the job.');
        }

        return $branchId;
    }
}
