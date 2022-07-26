<?php

declare(strict_types=1);

namespace App\Helper;

use Keboola\CommonExceptions\UserExceptionInterface;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\ErrorControl\Message\ExceptionTransformer;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\ObjectEncryptor\Exception\UserException as EncryptionUserException;
use Psr\Log\LoggerInterface;
use Throwable;

class ExceptionConverter
{
    public static function convertExceptionToResult(
        LoggerInterface $logger,
        Throwable $e,
        string $jobId,
        array $outputs
    ): JobResult {
        $transformedException = ExceptionTransformer::transformException($e);
        if (is_a($e, EncryptionUserException::class)) {
            $errorType = JobResult::ERROR_TYPE_USER;
            $logger->error(
                sprintf('Job "%s" ended with encryption error: "%s"', $jobId, $transformedException->getError()),
                $transformedException->getFullArray()
            );
        } elseif (is_a($e, UserExceptionInterface::class)) {
            $errorType = JobResult::ERROR_TYPE_USER;
            $logger->error(
                sprintf('Job "%s" ended with user error: "%s"', $jobId, $transformedException->getError()),
                $transformedException->getFullArray()
            );
        } else {
            $errorType = JobResult::ERROR_TYPE_APPLICATION;
            $logger->critical(
                sprintf('Job "%s" ended with application error: "%s"', $jobId, $transformedException->getError()),
                $transformedException->getFullArray()
            );
        }
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
