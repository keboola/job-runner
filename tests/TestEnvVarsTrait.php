<?php

declare(strict_types=1);

namespace App\Tests;

trait TestEnvVarsTrait
{
    abstract public static function assertNotEmpty(mixed $actual, string $message = ''): void;

    /**
     * @return non-empty-string|null
     */
    protected static function getOptionalEnv(string $name): ?string
    {
        $value = (string) getenv($name);
        return $value !== '' ? $value : null;
    }

    /**
     * @return non-empty-string
     */
    protected static function getRequiredEnv(string $name): string
    {
        $value = (string) getenv($name);
        self::assertNotEmpty($value);

        return $value;
    }
}
