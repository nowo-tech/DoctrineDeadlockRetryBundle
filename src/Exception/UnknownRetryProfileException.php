<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * Thrown when an unknown retry profile name is requested.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UnknownRetryProfileException extends InvalidArgumentException
{
    /**
     * @param string $name Requested profile name
     * @param list<string> $availableProfiles Configured profile names
     */
    public static function forName(string $name, array $availableProfiles): self
    {
        $available = $availableProfiles === [] ? '(none)' : implode(', ', $availableProfiles);

        return new self(sprintf(
            'Unknown Doctrine deadlock retry profile "%s". Available profiles: %s.',
            $name,
            $available,
        ));
    }
}
