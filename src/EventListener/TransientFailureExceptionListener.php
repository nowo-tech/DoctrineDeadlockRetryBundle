<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\EventListener;

use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_a;
use function is_callable;
use function is_int;
use function is_string;
use function max;
use function str_starts_with;

/**
 * Maps transient storage failures that reach the kernel (retries exhausted, or never retried) to 503 Service
 * Unavailable with Retry-After, and conflict failures to 409 Conflict, instead of a 500 (opt-in: http_mapping).
 *
 * Transient: whatever TransientFailureClassifier recognises (deadlock, lock wait timeout, serialization failure, the
 * 1305 lost savepoint after a nested deadlock, any DBAL RetryableException) and the framework-free
 * Nowo\TransactionContract\TransientStorageFailure, whose retryAfterSeconds() hint wins over the configured value.
 *
 * Format "exception" (default) replaces the throwable with a Symfony HttpException carrying the status and headers,
 * so the host's own error rendering (problem+json included) produces the response. Format "problem_json" sets an
 * RFC 9457 application/problem+json response directly. Either way the client only sees a generic text: SQL,
 * parameters and driver messages stay in the previous exception, for the logs.
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

    /** Before Symfony's ErrorListener (-128) and API Platform's error handling, after the security listener (1). */
    public const PRIORITY = 0;

    /** Framework-free failure of nowo-tech/transaction-contract, matched by name: that package is optional. */
    public const TRANSIENT_STORAGE_FAILURE = 'Nowo\TransactionContract\TransientStorageFailure';

    public const UNAVAILABLE_DETAIL = 'The request could not be completed because of a temporary storage conflict. Retry later.';
    public const CONFLICT_DETAIL    = 'The request conflicts with a concurrent change. Reload the resource and retry.';

    private readonly TransientFailureClassifier $classifier;

    /**
     * @param int $retryAfterSeconds Retry-After value of a 503
     * @param list<string> $pathPrefixes Request path prefixes handled (with $routePrefixes empty too: every request)
     * @param list<string> $routePrefixes Route name prefixes handled
     * @param list<string> $conflictExceptions Exception classes (or interfaces) mapped to 409, searched along the previous chain
     * @param string $format FORMAT_EXCEPTION or FORMAT_PROBLEM_JSON
     */
    public function __construct(
        private readonly int $retryAfterSeconds = 1,
        private readonly array $pathPrefixes = [],
        private readonly array $routePrefixes = [],
        private readonly array $conflictExceptions = [],
        private readonly string $format = self::FORMAT_EXCEPTION,
        ?TransientFailureClassifier $classifier = null,
    ) {
        $this->classifier = $classifier ?? new TransientFailureClassifier();
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

        $retryAfter = $this->retryAfter($throwable);

        if (is_int($retryAfter)) {
            $headers = ['Retry-After' => (string) $retryAfter];

            if ($this->format === self::FORMAT_PROBLEM_JSON) {
                $event->setResponse($this->problem(503, 'Service Unavailable', self::UNAVAILABLE_DETAIL, $headers));

                return;
            }

            $event->setThrowable(new ServiceUnavailableHttpException((string) $retryAfter, self::UNAVAILABLE_DETAIL, $throwable));

            return;
        }

        if (!$this->isConflict($throwable)) {
            return;
        }

        if ($this->format === self::FORMAT_PROBLEM_JSON) {
            $event->setResponse($this->problem(409, 'Conflict', self::CONFLICT_DETAIL, []));

            return;
        }

        $event->setThrowable(new ConflictHttpException(self::CONFLICT_DETAIL, $throwable));
    }

    private function handles(Request $request): bool
    {
        if ($this->pathPrefixes === [] && $this->routePrefixes === []) {
            return true;
        }

        $path = $request->getPathInfo();

        foreach ($this->pathPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
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
     * @return int|null Seconds for Retry-After, or null when the failure is not transient
     */
    private function retryAfter(Throwable $throwable): ?int
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if (is_a($current, self::TRANSIENT_STORAGE_FAILURE)) {
                $hint = $this->hint($current);

                return is_int($hint) ? max(0, $hint) : $this->retryAfterSeconds;
            }
        }

        return $this->classifier->isTransient($throwable) ? $this->retryAfterSeconds : null;
    }

    /**
     * Reads TransientStorageFailure::retryAfterSeconds() without a compile-time dependency on the contract package.
     */
    private function hint(object $failure): mixed
    {
        $accessor = [$failure, 'retryAfterSeconds'];

        return is_callable($accessor) ? $accessor() : null;
    }

    private function isConflict(Throwable $throwable): bool
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            foreach ($this->conflictExceptions as $class) {
                if (is_a($current, $class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private function problem(int $status, string $title, string $detail, array $headers): JsonResponse
    {
        return new JsonResponse(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        );
    }
}
