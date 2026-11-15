<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\EventListener;

use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Runs the ConnectionGuard after every message a Messenger worker handled or failed (opt-in: worker_guard.enabled
 * and worker_guard.messenger), so a message never inherits a poisoned connection, a closed manager or the pending
 * changes of the previous one.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MessengerWorkerGuardSubscriber implements EventSubscriberInterface
{
    /** After the host's own listeners (Messenger's retry and failure listeners run at the default priorities). */
    public const PRIORITY = -2048;

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
            WorkerMessageHandledEvent::class => ['afterMessage', self::PRIORITY],
            WorkerMessageFailedEvent::class  => ['afterMessage', self::PRIORITY],
        ];
    }

    public function afterMessage(): void
    {
        $this->guard->repair('messenger', true);
    }
}
