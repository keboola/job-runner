<?php

declare(strict_types=1);

namespace App;

use Keboola\BillingApi\ClientFactory;
use Keboola\BillingApi\CreditsChecker;
use Keboola\StorageApi\Client;

class CreditsCheckerFactory
{
    public function getCreditsChecker(Client $client): CreditsChecker
    {
        $clientFactory = new ClientFactory();
        return new CreditsChecker($clientFactory, $client);
    }
}
