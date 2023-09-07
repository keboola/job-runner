<?php

declare(strict_types=1);

namespace App\Tests;

use Keboola\ObjectEncryptor\EncryptorOptions;

trait EncryptorOptionsTrait
{
    protected function getEncryptorOptions(): EncryptorOptions
    {
        $stackId = (string) getenv('ENCRYPTOR_STACK_ID');
        self::assertNotEmpty($stackId);

        $awsKmsKeyId = (string) getenv('AWS_KMS_KEY_ID');
        self::assertNotEmpty($awsKmsKeyId);

        $awsRegion = (string) getenv('AWS_REGION');
        self::assertNotEmpty($awsRegion);

        $azureKeyVaultUrl = (string) getenv('AZURE_KEY_VAULT_URL');
        self::assertNotEmpty($azureKeyVaultUrl);

        $gcpKmsKeyId = (string) getenv('GCP_KMS_KEY_ID');
        self::assertNotEmpty($gcpKmsKeyId);

        return new EncryptorOptions(
            $stackId,
            $awsKmsKeyId,
            $awsRegion,
            null,
            $azureKeyVaultUrl,
            $gcpKmsKeyId,
        );
    }
}
