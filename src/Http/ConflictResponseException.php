<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Http;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * 409 Conflict that carries its error code: CONFLICT_001 / CONFLICT_002 for a lock conflict, or the domain code of a
 * configured conflict exception.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictResponseException extends ConflictHttpException implements HasErrorCodeInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(private readonly string $errorCode, string $message, ?Throwable $previous = null, array $headers = [])
    {
        parent::__construct($message, $previous, 0, $headers);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
