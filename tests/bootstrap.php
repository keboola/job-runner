<?php

declare(strict_types=1);

use Keboola\StorageApi\Client;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->usePutenv()->bootEnv(dirname(__DIR__).'/.env', 'dev', []);

$requiredEnvs = ['CPU_COUNT', 'JOB_QUEUE_URL', 'JOB_QUEUE_TOKEN',
    'STORAGE_API_URL', 'VAULT_API_URL', 'AWS_REGION', 'AWS_KMS_KEY_ID',
    'AZURE_KEY_VAULT_URL', 'GCP_KMS_KEY_ID', 'ENCRYPTOR_STACK_ID',
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
        ],
    );
    $tokenInfo = $client->verifyToken();

    print(sprintf(
        'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.' . "\n",
        $tokenInfo['description'],
        $tokenInfo['id'],
        $tokenInfo['owner']['name'],
        $tokenInfo['owner']['id'],
        $client->getApiUrl(),
    ));
}

$sensitiveVariables = [
    'STORAGE_API_TOKEN',
    'STORAGE_API_TOKEN_MASTER',
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AZURE_CLIENT_ID',
    'AZURE_CLIENT_SECRET',
    'AZURE_TENANT_ID',
    'GOOGLE_APPLICATION_CREDENTIALS',
];
foreach ($sensitiveVariables as $variable) {
    // clear any real values
    unset($_SERVER[$variable]);
    putenv($variable);

    // move TEST_* values
    $testVariable = 'TEST_'.$variable;
    if (array_key_exists($testVariable, $_SERVER)) {
        $_SERVER[$variable] = $_SERVER[$testVariable];
    }

    if (getenv($testVariable) !== false) {
        putenv($variable.'='.getenv($testVariable));
    }
}
