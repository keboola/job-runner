<?php

// helper script to encrypt a value so that it can be decrypted by the runner
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;

require __DIR__ . '/../vendor/autoload.php';

$objectEncryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
    getenv('ENCRYPTOR_STACK_ID'),
    getenv('AWS_KMS_KEY_ID'),
    getenv('AWS_REGION'),
    ((string) getenv('AWS_KMS_ROLE')) ?: null,
    getenv('AZURE_KEY_VAULT_URL')
));

echo $objectEncryptor->encryptGeneric($argv[1]);
