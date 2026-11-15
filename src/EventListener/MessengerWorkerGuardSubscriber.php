<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\EventListener;

use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Runs the ConnectionGuard around the end of every message a Messenger worker handled or failed (opt-in:
 * worker_guard.enabled and worker_guard.messenger), so a message never inherits a poisoned connection, a closed
 * manager or the pending changes of the previous one.
 *
 * On a failed message the connections are repaired early (REPAIR_PRIORITY), before Messenger's
 * SendFailedMessageForRetryListener (100) writes the retry envelope: with a Doctrine transport on the same connection,
 * a transaction the failed handler left open would otherwise swallow that write and roll it back. Entity managers are
 * cleared last (CLEAR_PRIORITY), after every other listener, on both events.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MessengerWorkerGuardSubscriber implements EventSubscriberInterface
{
    /** Before SendFailedMessageForRetryListener (100). */
    public const REPAIR_PRIORITY = 512;

    /** After the host's own listeners. */
    public const CLEAR_PRIORITY = -2048;

    public function __construct(
        private readonly ConnectionGuard $guard,
    ) {
    }

    /**
     * @return array<string, list<array{string, int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => [['afterMessage', self::CLEAR_PRIORITY]],
            WorkerMessageFailedEvent::class  => [['repairBeforeRetry', self::REPAIR_PRIORITY], ['afterMessage', self::CLEAR_PRIORITY]],
        ];
    }

    public function repairBeforeRetry(): void
    {
        $this->guard->repair('messenger');
    }

    public function afterMessage(): void
    {
        $this->guard->repair('messenger', true);
    }
}
