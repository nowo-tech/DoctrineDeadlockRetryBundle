<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Domain;

/**
 * Plain-PHP aggregate of a DDD host: no Doctrine attribute, no bundle base class or trait.
 * It records domain events; the application layer releases them after the transaction commits.
 */
final class Order
{
    private bool $paid = false;

    /** @var list<string> */
    private array $recordedEvents = [];

    public function __construct(private readonly string $id)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function pay(): void
    {
        if ($this->paid) {
            return;
        }

        $this->paid             = true;
        $this->recordedEvents[] = 'order.paid:' . $this->id;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    /**
     * @return list<string>
     */
    public function releaseEvents(): array
    {
        $events               = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
