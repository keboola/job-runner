<?php

declare(strict_types=1);

namespace App;

use Keboola\BillingApi\CreditsChecker;
use Keboola\StorageApi\Client;

class CreditsCheckerFactory
{
    public function getCreditsChecker(Client $client): CreditsChecker
    {
        return new CreditsChecker($client);
    }
}
