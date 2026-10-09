<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\GitHubApp\GitHubAppTokenClient;
use Keboola\ServiceClient\Service;
use Keboola\ServiceClient\ServiceClient;
use Keboola\ServiceClient\ServiceDnsType;

class GitHubAppTokenClientFactory
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly string $applicationToken,
    ) {
    }

    public function create(): GitHubAppTokenClient
    {
        return new GitHubAppTokenClient(
            $this->serviceClient->getServiceUrl(Service::OAUTH, ServiceDnsType::INTERNAL),
            $this->applicationToken,
        );
    }
}
