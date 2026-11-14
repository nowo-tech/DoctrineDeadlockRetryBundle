<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\EventListener;

use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs the ConnectionGuard early on every main request and late on kernel.terminate (opt-in: worker_guard.enabled).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WorkerGuardSubscriber implements EventSubscriberInterface
{
    /** Before the router (32) and the firewall (8), so no listener of this request sees a poisoned connection. */
    public const REQUEST_PRIORITY = 2048;

    /** After the host's own terminate listeners, so their leftovers are repaired too. */
    public const TERMINATE_PRIORITY = -2048;

    public function __construct(
        private readonly ConnectionGuard $guard,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onKernelRequest', self::REQUEST_PRIORITY],
            KernelEvents::TERMINATE => ['onKernelTerminate', self::TERMINATE_PRIORITY],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->guard->repair(KernelEvents::REQUEST);
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->guard->repair(KernelEvents::TERMINATE);
    }
}
