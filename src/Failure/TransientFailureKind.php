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

    /** MySQL / MariaDB 1205. */
    case LockWaitTimeout = 'lock_wait_timeout';

    /** SQLSTATE 40001 without a more precise code (PostgreSQL serialization failure). */
    case SerializationFailure = 'serialization_failure';

    /**
     * MySQL / MariaDB 1305 on a savepoint Doctrine created: the server already rolled the whole transaction back
     * (a deadlock in a nested transaction), and the ORM's failed ROLLBACK TO SAVEPOINT replaced the deadlock error.
     */
    case LostSavepoint = 'lost_savepoint';

    /** Any other Doctrine DBAL RetryableException. */
    case Retryable = 'retryable';
}
