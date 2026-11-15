<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Failure;

/**
 * Kind of transient storage failure: replaying the whole transaction later can succeed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
enum TransientFailureKind: string
{
    /** MySQL / MariaDB 1213, PostgreSQL 40P01. */
    case Deadlock = 'deadlock';

    /** MySQL / MariaDB 1205, PostgreSQL 55P03 (lock_timeout, NOWAIT). */
    case LockWaitTimeout = 'lock_wait_timeout';

    /** SQLSTATE 40001 without a more precise code (PostgreSQL), MariaDB 1020 (snapshot isolation conflict). */
    case SerializationFailure = 'serialization_failure';

    /** Any other Doctrine DBAL RetryableException. */
    case Retryable = 'retryable';
}
