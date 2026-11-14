<?php

declare(strict_types=1);

namespace Nowo\TransactionContract;

use RuntimeException;
use Throwable;

/*
 * Stand-in for nowo-tech/transaction-contract's exception, which this bundle matches by name until it requires that
 * package. Loaded only when the real class is absent.
 */
if (!class_exists(TransientStorageFailure::class, false)) {
    class TransientStorageFailure extends RuntimeException
    {
        public function __construct(private readonly ?int $retryAfterSeconds = null, ?Throwable $previous = null)
        {
            parent::__construct('The storage is temporarily unavailable. Retry later.', 0, $previous);
        }

        public function retryAfterSeconds(): ?int
        {
            return $this->retryAfterSeconds;
        }
    }
}
