<?php

declare(strict_types=1);

namespace App;

use Keboola\ObjectEncryptor\Legacy\Wrapper\BaseWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptor;

class FakeEncryptor extends ObjectEncryptor
{
    /**
     * @param string|array|\stdClass $data Data to encrypt
     * @param string $wrapperName Class name of encryptor wrapper
     * @return mixed
     */
    public function encrypt($data, $wrapperName = BaseWrapper::class)
    {
        return $data;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function decrypt($data)
    {
        return $data;
    }
}
