<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\EventListener;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use InvalidArgumentException;
use Nowo\DoctrineDeadlockRetryBundle\Failure\ThrowableChain;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Http\ConflictResponseException;
use Nowo\DoctrineDeadlockRetryBundle\Http\UnavailableResponseException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier as CommitOutcomeClassifier;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function in_array;
use function is_string;
use function rtrim;
use function str_starts_with;

/**
 * Maps lock conflicts that reach the kernel (retries exhausted, or never retried) to 409 Conflict with a stable code
 * instead of a 500 (opt-in: http_mapping), as REQ-RUNTIME-003 rule 6 asks:
 *
 * - CONFLICT_001: nothing was committed. 409 + Retry-After (the replay is safe).
 * - CONFLICT_002: part of the work may be committed (a ConcurrencyConflict flagged committed, or a lost savepoint after
 *   a failed commit). 409 and NO Retry-After: the client must reload before it tries again.
 *
 * A host that prefers the old answer sets `conflict_status: 503`: same codes, 503 instead of 409. Unique-constraint
 * violations and connection losses are NOT lock conflicts and are never mapped here; a configured conflict exception
 * (default: the ORM's OptimisticLockException) answers 409 with its own domain code (DOMAIN_CODE), never CONFLICT_*.
 *
 * Lock conflict: whatever TransientFailureClassifier recognises (deadlock, lock wait timeout, serialization failure, the
 * ORM's 1305 that carries a nested deadlock, any DBAL RetryableException) and the framework-free
 * Nowo\TransactionContract\TransientStorageFailure, whose retryAfterSeconds() hint wins over the configured value.
 * A TransientStorageFailure is found anywhere in the previous chain or inside a Messenger HandlerFailedException; other
 * transient failures follow TransientFailureClassifier (inside a HandlerFailedException, every wrapped exception must
 * be transient), and so do the conflict classes (every wrapped exception must be a conflict).
 *
 * Format "exception" (default) replaces the throwable with a Symfony HttpException (HasErrorCodeInterface) carrying the
 * status and headers, so the host's own error rendering (problem+json included) produces the response. Format
 * "problem_json" sets an RFC 9457 application/problem+json response directly, with the code in an additive "code" key.
 * Either way the client only sees a generic text: SQL, parameters and driver messages stay in the previous exception,
 * for the logs.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureExceptionListener implements EventSubscriberInterface
{
    public const FORMAT_EXCEPTION    = 'exception';
    public const FORMAT_PROBLEM_JSON = 'problem_json';

    /**
     * After Symfony's ErrorListener::logKernelException (0), so the failure is always logged even when this listener
     * sets the response, and before its rendering (-128).
     */
    public const PRIORITY = -64;

    public const UNAVAILABLE_DETAIL = 'The request could not be completed because of a temporary storage conflict. Retry later.';
    public const CONFLICT_DETAIL    = 'The request conflicts with a concurrent change. Reload the resource and retry.';
    public const COMMITTED_DETAIL   = 'The request met a temporary storage conflict after part of it was saved. Reload the resource before trying again.';

    /** Error code of a configured conflict exception (a domain conflict, never CONFLICT_*). */
    public const DOMAIN_CODE = 'RESOURCE_CHANGED';

    /** Response header carrying CONFLICT_001 / CONFLICT_002 (never the domain code), in both formats and statuses. */
    public const CODE_HEADER = 'X-Conflict-Code';

    public const STATUS_CONFLICT            = 409;
    public const STATUS_SERVICE_UNAVAILABLE = 503;

    private readonly TransientFailureClassifier $classifier;
    private readonly CommitOutcomeClassifier $commitOutcome;

    /**
     * @param int $retryAfterSeconds Retry-After value of a 503
     * @param list<string> $pathPrefixes Request path prefixes handled (with $routePrefixes empty too: every request)
     * @param list<string> $routePrefixes Route name prefixes handled
     * @param list<string> $conflictExceptions Exception classes (or interfaces) mapped to 409, searched along the previous chain; inside a HandlerFailedException every wrapped exception must match
     * @param string $format FORMAT_EXCEPTION or FORMAT_PROBLEM_JSON
     * @param int $conflictStatus Status of a lock conflict: 409 (default) or 503 (hosts that keep the old answer)
     */
    public function __construct(
        private readonly int $retryAfterSeconds = 1,
        private readonly array $pathPrefixes = [],
        private readonly array $routePrefixes = [],
        private readonly array $conflictExceptions = [],
        private readonly string $format = self::FORMAT_EXCEPTION,
        ?TransientFailureClassifier $classifier = null,
        private readonly int $conflictStatus = self::STATUS_CONFLICT,
        ?CommitOutcomeClassifier $commitOutcome = null,
    ) {
        if (!in_array($conflictStatus, [self::STATUS_CONFLICT, self::STATUS_SERVICE_UNAVAILABLE], true)) {
            throw new InvalidArgumentException('The conflict status must be 409 or 503.');
        }

        $this->classifier    = $classifier ?? new TransientFailureClassifier();
        $this->commitOutcome = $commitOutcome ?? new CommitOutcomeClassifier();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', self::PRIORITY]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof HttpExceptionInterface || !$this->handles($event->getRequest())) {
            return;
        }

        $transient = $this->classifier->isTransient($throwable);
        $storage   = $this->classifier->storageFailure($throwable);
        $hint      = $storage?->retryAfterSeconds();

        // A lock conflict: replayable, or a TransientStorageFailure over a non-storage error, or a transient driver error
        // hidden behind a wrapper that cannot be replayed (completed handler, delayed dispatch), or a failed commit.
        if ($transient || $storage instanceof TransientStorageFailure || $this->hiddenTransient($throwable) || $this->commitOutcome->isCommitOutcomeUnknown($throwable)) {
            // Not replayable (the work may be committed): CONFLICT_002 whatever the other signals say.
            $code = $transient ? $this->conflictCode($throwable) : ConflictCode::Committed;
            // CONFLICT_002 never carries Retry-After: replaying could repeat what was already saved.
            $this->answerLockConflict($event, $throwable, $code, $code->retrySafe() ? ($hint ?? $this->retryAfterSeconds) : null);

            return;
        }

        if (!$this->isConflict($throwable)) {
            return;
        }

        if ($this->format === self::FORMAT_PROBLEM_JSON) {
            $event->setResponse($this->problem(409, 'Conflict', self::CONFLICT_DETAIL, [], self::DOMAIN_CODE));

            return;
        }

        $event->setThrowable(new ConflictResponseException(self::DOMAIN_CODE, self::CONFLICT_DETAIL, $throwable));
    }

    private function answerLockConflict(ExceptionEvent $event, Throwable $throwable, ConflictCode $code, ?int $retryAfter): void
    {
        $detail  = $code->retrySafe() ? ($this->conflictStatus === self::STATUS_CONFLICT ? self::CONFLICT_DETAIL : self::UNAVAILABLE_DETAIL) : self::COMMITTED_DETAIL;
        $headers = ($retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter]) + [self::CODE_HEADER => $code->value];

        if ($this->format === self::FORMAT_PROBLEM_JSON) {
            $title = $this->conflictStatus === self::STATUS_CONFLICT ? 'Conflict' : 'Service Unavailable';
            $event->setResponse($this->problem($this->conflictStatus, $title, $detail, $headers, $code->value));

            return;
        }

        $event->setThrowable(
            $this->conflictStatus === self::STATUS_CONFLICT
                ? new ConflictResponseException($code->value, $detail, $throwable, $headers)
                : $this->unavailable($code, $retryAfter, $detail, $throwable, $headers),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function unavailable(ConflictCode $code, ?int $retryAfter, string $detail, Throwable $throwable, array $headers): UnavailableResponseException
    {
        $exception = new UnavailableResponseException($code->value, $retryAfter, $detail, $throwable);
        $exception->setHeaders($exception->getHeaders() + $headers);

        return $exception;
    }

    /**
     * CONFLICT_002 as soon as anything in the chain, including every exception a Messenger HandlerFailedException wraps,
     * may be committed: a ConcurrencyConflict flagged committed, or a HandlerFailedException whose envelope carries a
     * HandledStamp (another handler completed). transaction-doctrine's forFailure() only follows getPrevious().
     */
    private function conflictCode(Throwable $throwable): ConflictCode
    {
        foreach (ThrowableChain::flatten($throwable) as $current) {
            if ($current instanceof ConcurrencyConflict && $current->committed) {
                return ConflictCode::Committed;
            }

            if ($this->isNonReplayableWrapper($current)) {
                return ConflictCode::Committed;
            }
        }

        return ConflictCode::forFailure($throwable);
    }

    /**
     * A wrapper that proves earlier work completed: a HandlerFailedException whose envelope carries a HandledStamp, or a
     * DelayedMessageHandlingException (the outer dispatch, and its transaction, already committed).
     */
    private function isNonReplayableWrapper(Throwable $throwable): bool
    {
        return $throwable instanceof DelayedMessageHandlingException
            || ($throwable instanceof HandlerFailedException && $throwable->getEnvelope()->all(HandledStamp::class) !== []);
    }

    /**
     * A transient driver error (deadlock, lock wait timeout, serialization failure) inside a wrapper that cannot be
     * replayed: it is a lock conflict on work that may be committed.
     */
    private function hiddenTransient(Throwable $throwable): bool
    {
        foreach (ThrowableChain::flatten($throwable) as $wrapper) {
            if (!$this->isNonReplayableWrapper($wrapper)) {
                continue;
            }

            foreach (ThrowableChain::flatten($wrapper) as $inner) {
                if ($inner !== $wrapper && $this->commitOutcome->isTransient($inner)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function handles(Request $request): bool
    {
        if ($this->pathPrefixes === [] && $this->routePrefixes === []) {
            return true;
        }

        $path = $request->getPathInfo();

        foreach ($this->pathPrefixes as $prefix) {
            $prefix = rtrim($prefix, '/');

            // Segment boundary: "/api" matches "/api" and "/api/orders", not "/apiary".
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        $route = $request->attributes->get('_route');

        if (!is_string($route)) {
            return false;
        }

        foreach ($this->routePrefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A configured class matches along the chain; inside a HandlerFailedException only when every wrapped exception is a
     * conflict. A matching exception that is not itself a driver exception but wraps one (the ORM's
     * OptimisticLockException("Commit failed") around a failed COMMIT, whose outcome is unknown) is not a conflict.
     */
    private function isConflict(Throwable $throwable): bool
    {
        return ThrowableChain::resolve($throwable, function (Throwable $current): Throwable|false|null {
            foreach ($this->conflictExceptions as $class) {
                if ($current instanceof $class) {
                    return $current instanceof DriverExceptionInterface || !$this->wrapsDriverException($current) ? $current : false;
                }
            }

            return null;
        }) instanceof Throwable;
    }

    private function wrapsDriverException(Throwable $throwable): bool
    {
        foreach (ThrowableChain::flatten($throwable) as $current) {
            if ($current !== $throwable && $current instanceof DriverExceptionInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private function problem(int $status, string $title, string $detail, array $headers, string $code): JsonResponse
    {
        return new JsonResponse(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'code' => $code],
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        );
    }
}
