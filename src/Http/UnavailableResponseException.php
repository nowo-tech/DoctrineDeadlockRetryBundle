<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Http;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * 503 Service Unavailable that carries its error code (CONFLICT_001 or CONFLICT_002): the answer of a lock conflict
 * when the host chose `conflict_status: 503`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UnavailableResponseException extends ServiceUnavailableHttpException implements HasErrorCodeInterface
{
    public function __construct(private readonly string $errorCode, ?int $retryAfter, string $message, ?Throwable $previous = null)
    {
        parent::__construct($retryAfter, $message, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
