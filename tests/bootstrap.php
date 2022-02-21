<?php

declare(strict_types=1);

use Keboola\StorageApi\Client;

require __DIR__ . '/../vendor/autoload.php';

$requiredEnvs = ['CPU_COUNT', 'LEGACY_OAUTH_API_URL', 'LEGACY_ENCRYPTION_KEY', 'JOB_QUEUE_URL', 'JOB_QUEUE_TOKEN',
    'STORAGE_API_URL', 'AWS_REGION', 'AWS_KMS_KEY_ID',
    'AZURE_KEY_VAULT_URL', 'AZURE_LOG_ABS_CONNECTION_STRING',
    'TEST_STORAGE_API_TOKEN', 'TEST_STORAGE_API_TOKEN_MASTER', 'TEST_AWS_ACCESS_KEY_ID',
    'TEST_AWS_SECRET_ACCESS_KEY', 'TEST_AZURE_CLIENT_ID',
    'TEST_AZURE_CLIENT_SECRET', 'TEST_AZURE_TENANT_ID',
];
foreach ($requiredEnvs as $env) {
    if (empty(getenv($env))) {
        throw new Exception(sprintf('The "%s" environment variable is empty.', $env));
    }
}

$tokeEnvs = ['TEST_STORAGE_API_TOKEN', 'TEST_STORAGE_API_TOKEN_MASTER'];
foreach ($tokeEnvs as $tokenEnv) {
    $client = new Client(
        [
            'token' => (string) getenv($tokenEnv),
            'url' => (string) getenv('STORAGE_API_URL'),
        ]
    );
    $tokenInfo = $client->verifyToken();

    print(sprintf(
        'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.' . "\n",
        $tokenInfo['description'],
        $tokenInfo['id'],
        $tokenInfo['owner']['name'],
        $tokenInfo['owner']['id'],
        $client->getApiUrl()
    ));
}
