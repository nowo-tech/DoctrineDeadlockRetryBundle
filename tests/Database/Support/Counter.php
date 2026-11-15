<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * Row the real-database tests lock and update from two sessions.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_counter')]
class Counter
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(name: 'counter_value', type: 'integer')]
        public int $value = 0,
    ) {
    }
}
