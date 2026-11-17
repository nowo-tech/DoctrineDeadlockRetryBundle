<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Failure;

use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\Failure\ThrowableChain;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ThrowableChainTest extends TestCase
{
    public function testAPlainChainKeepsThePreviousOrder(): void
    {
        $root   = new RuntimeException('root');
        $middle = new LogicException('middle', 0, $root);
        $top    = new RuntimeException('top', 0, $middle);

        self::assertSame([$top, $middle, $root], ThrowableChain::flatten($top));
    }

    public function testHandlerFailedExceptionIsWalkedRecursivelyEachThrowableOnce(): void
    {
        $first      = new RuntimeException('first handler');
        $deepCause  = new RuntimeException('deep cause');
        $second     = new LogicException('second handler', 0, $deepCause);
        $nestedOnly = new RuntimeException('nested handler');
        $nested     = new HandlerFailedException(new Envelope(new stdClass()), ['n' => $nestedOnly]);
        $failed     = new HandlerFailedException(new Envelope(new stdClass()), ['a' => $first, 'b' => $second, 'c' => $nested]);

        // Previous first (the first handler's exception), then the remaining wrapped ones, depth-first.
        self::assertSame([$failed, $first, $second, $deepCause, $nested, $nestedOnly], ThrowableChain::flatten($failed));
    }

    public function testDepthIsBounded(): void
    {
        $deepest = new RuntimeException('deepest');
        $current = $deepest;

        for ($i = 0; $i < ThrowableChain::MAX_DEPTH + 10; ++$i) {
            $current = new RuntimeException('level', 0, $current);
        }

        $flattened = ThrowableChain::flatten($current);

        self::assertCount(ThrowableChain::MAX_DEPTH + 1, $flattened);
        self::assertNotContains($deepest, $flattened);
    }

    public function testAThrowableReachedAgainByAShorterPathIsExpandedFromThere(): void
    {
        $deadlockCause = new RuntimeException('carried by the shared exception');
        $shared        = new RuntimeException('shared', 0, $deadlockCause);
        $longBranch    = $shared;

        for ($i = 0; $i < ThrowableChain::MAX_DEPTH - 1; ++$i) {
            $longBranch = new RuntimeException('wrapper', 0, $longBranch);
        }

        // The first branch reaches $shared at MAX_DEPTH (not expanded), the second one at depth 1.
        $failed    = new HandlerFailedException(new Envelope(new stdClass()), ['long' => $longBranch, 'short' => $shared]);
        $flattened = ThrowableChain::flatten($failed);

        self::assertContains($deadlockCause, $flattened);
        self::assertCount(ThrowableChain::MAX_DEPTH + 2, $flattened, 'Each throwable listed once.');
    }

    public function testASelfReferencingWrapperTerminates(): void
    {
        $leaf    = new RuntimeException('leaf');
        $wrapper = new class('loop', $leaf) extends RuntimeException implements WrappedExceptionsInterface {
            public function __construct(string $message, private readonly Throwable $extra)
            {
                parent::__construct($message);
            }

            /**
             * @return list<Throwable>
             */
            public function getWrappedExceptions(?string $class = null, bool $recursive = false): array
            {
                return [$this, $this->extra, $this];
            }
        };

        self::assertSame([$wrapper, $leaf], ThrowableChain::flatten($wrapper));
    }

    public function testFanOutIsBounded(): void
    {
        $handlers = [];

        for ($i = 0; $i < ThrowableChain::MAX_NODES * 2; ++$i) {
            $handlers['h' . $i] = new RuntimeException('handler ' . $i);
        }

        $failed = new HandlerFailedException(new Envelope(new stdClass()), $handlers);

        self::assertCount(ThrowableChain::MAX_NODES, ThrowableChain::flatten($failed));
    }
}
