<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\ErrorControl\Message\ExceptionTransformer;
use Keboola\JobQueueInternalClient\JobFactory\JobResult;
use Keboola\ObjectEncryptor\Exception\UserException as EncryptionUserException;
use Psr\Log\LoggerInterface;
use Throwable;

class ExceptionConverterHelper
{
    public static function convertExceptionToResult(
        LoggerInterface $logger,
        Throwable $e,
        string $jobId,
        array $outputs
    ): JobResult {
        if (is_a($e, UserException::class)) {
            $errorTypeString = 'user';
            $errorType = JobResult::ERROR_TYPE_USER;
        } elseif (is_a($e, EncryptionUserException::class)) {
            $errorTypeString = 'encryption';
            $errorType = JobResult::ERROR_TYPE_USER;
        } else {
            $errorTypeString = 'application';
            $errorType = JobResult::ERROR_TYPE_APPLICATION;
        }
        $transformedException = ExceptionTransformer::transformException($e);
        $logger->error(
            sprintf('Job "%s" ended with %s error: "%s"', $jobId, $errorTypeString, $transformedException->getError()),
            $transformedException->getFullArray()
        );
        $result = new JobResult();
        $result->setMessage($transformedException->getError())
            ->setErrorType($errorType)
            ->setExceptionId($transformedException->getExceptionId());
        if ($outputs) {
            $result
                ->setConfigVersion((string) $outputs[0]->getConfigVersion())
                ->setImages(array_map(fn(Output $output) => $output->getImages(), $outputs));
        }
        return $result;
    }
}
