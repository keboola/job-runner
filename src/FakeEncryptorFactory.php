<?php

declare(strict_types=1);

namespace App;

use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;

class FakeEncryptorFactory extends ObjectEncryptorFactory
{
    public function __construct()
    {
    }

    /**
     * @param bool $createLegacyEncryptorIfAvailable
     * @return ObjectEncryptor Object encryptor instance.
     */
    public function getEncryptor($createLegacyEncryptorIfAvailable = false)
    {
        return new FakeEncryptor(null);
    }
}
