<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Http;

/**
 * An HttpException that carries the stable machine-readable error code of the answer (CONFLICT_001, CONFLICT_002 or the
 * domain code of a configured conflict exception), so the host's error rendering can put it in its own JSON shape.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface HasErrorCodeInterface
{
    public function errorCode(): string;
}
