<?php

// helper script to encrypt a value so that it can be decrypted by the runner
require __DIR__ . '/../vendor/autoload.php';

$of = new \Keboola\ObjectEncryptor\ObjectEncryptorFactory(
    getenv('AWS_KMS_KEY'),
    getenv('AWS_REGION'),
    '',
    '',
    getenv('AZURE_KEY_VAULT_URL')
);
$of->setStackId((string) parse_url(getenv('STORAGE_API_URL'), PHP_URL_HOST));
echo $of->getEncryptor()->encrypt($argv[1], \Keboola\ObjectEncryptor\Wrapper\GenericKMSWrapper::class);
