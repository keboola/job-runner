<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$requiredEnvs = ['CPU_COUNT', 'LEGACY_OAUTH_API_URL', 'LEGACY_ENCRYPTION_KEY', 'JOB_QUEUE_URL', 'JOB_QUEUE_TOKEN',
    'STORAGE_API_URL', 'AWS_REGION', 'AWS_KMS_KEY_ID', 'AWS_LOGS_S3_BUCKET',
    'AZURE_KEY_VAULT_URL', 'AZURE_LOG_ABS_CONNECTION_STRING', 'AZURE_LOG_ABS_CONTAINER',
    'TEST_STORAGE_API_TOKEN', 'TEST_STORAGE_API_TOKEN_MASTER', 'TEST_AWS_ACCESS_KEY_ID',
    'TEST_AWS_SECRET_ACCESS_KEY', 'TEST_AZURE_CLIENT_ID',
    'TEST_AZURE_CLIENT_SECRET', 'TEST_AZURE_TENANT_ID',
];
foreach ($requiredEnvs as $env) {
    if (empty(getenv($env))) {
        throw new \Exception(sprintf('The "%s" environment variable is empty.', $env));
    }
}
