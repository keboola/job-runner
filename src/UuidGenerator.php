<?php

namespace App;

use Symfony\Component\Uid\Uuid;

class UuidGenerator
{
    public function generateUuidV4(): string
    {
        return (string) Uuid::v4();
    }
}
