<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\ErrorControl\Message\ExceptionTransformer;
use Keboola\JobQueueInternalClient\JobFactory\JobResult;
use Psr\Log\LoggerInterface;
use Throwable;

class ExceptionConverterHelper
{
    public static function convertExceptionToResult(LoggerInterface $logger, Throwable $e, string $jobId): JobResult
    {
        $errorType = is_a($e,UserException::class) ? JobResult::ERROR_TYPE_USER : JobResult::ERROR_TYPE_APPLICATION;
        if (is_a($e, UserException::class)) {
            $errorTypeString = 'user';
        } elseif (is_a($e, \Keboola\ObjectEncryptor\Exception\UserException::class)) {
            $errorTypeString = 'encryption';
        } else {
            $errorTypeString = 'application';
        }
        $transformedException = ExceptionTransformer::transformException($e);
        $logger->error(
            sprintf('Job "%s" ended with %s error: "%s"', $jobId, $errorTypeString, $transformedException->getError()),
            $transformedException->getFullArray()
        );
        return (new JobResult())
            ->setMessage($transformedException->getError())
            ->setErrorType($errorType)
            ->setExceptionId($transformedException->getExceptionId());
    }
}
