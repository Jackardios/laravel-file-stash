<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use Jackardios\FileStash\Support\LockManager;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LockManagerTest extends TestCase
{
    protected string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = sys_get_temp_dir().'/file_stash_lock_'.bin2hex(random_bytes(8)).'.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        parent::tearDown();
    }

    /**
     * A stream without flock support must fail fast, not spin forever (or
     * poll until the timeout) as if the lock were merely contended.
     */
    #[DataProvider('timeoutProvider')]
    public function testFlockWithTimeoutFailsFastWithoutFlockSupport(float $timeout): void
    {
        $stream = fopen('php://memory', 'r+');
        $start = microtime(true);

        $this->assertFalse(LockManager::flockWithTimeout($stream, LOCK_SH, $timeout));
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    public static function timeoutProvider(): array
    {
        return ['indefinite' => [-1.0], 'bounded' => [5.0]];
    }

    public function testFlockWithTimeoutWaitsIndefinitelyForContendedLock(): void
    {
        $foreign = fopen($this->lockPath, 'c+');
        $this->assertTrue(flock($foreign, LOCK_EX | LOCK_NB));
        $own = fopen($this->lockPath, 'c+');

        $this->assertFalse(LockManager::flockWithTimeout($own, LOCK_SH, 0.0));

        fclose($foreign);
        $this->assertTrue(LockManager::flockWithTimeout($own, LOCK_SH, -1.0));
        fclose($own);
    }

    public function testHeldLifecycleTypeReflectsCurrentFrame(): void
    {
        $this->assertNull(LockManager::heldLifecycleType($this->lockPath));

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () {
            $this->assertSame(LOCK_SH, LockManager::heldLifecycleType($this->lockPath));
        });

        LockManager::withLifecycleLock($this->lockPath, LOCK_EX, 1.0, function () {
            $this->assertSame(LOCK_EX, LockManager::heldLifecycleType($this->lockPath));
        });

        $this->assertNull(LockManager::heldLifecycleType($this->lockPath));
    }

    public function testExclusiveUnderSharedThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () {
            LockManager::withLifecycleLock($this->lockPath, LOCK_EX, 1.0, fn () => null);
        });
    }

    public function testLockTimeoutThrowsTypedException(): void
    {
        $foreign = fopen($this->lockPath, 'c+');
        $this->assertTrue(flock($foreign, LOCK_EX));

        try {
            $this->expectException(LifecycleLockTimeoutException::class);
            $this->expectExceptionMessage('lifecycle lock');

            LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 0.0, fn () => null);
        } finally {
            flock($foreign, LOCK_UN);
            fclose($foreign);
        }
    }

    public function testReleaseHookRunsOnceAfterOutermostRelease(): void
    {
        $calls = [];

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$calls) {
            LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$calls) {
                LockManager::onOutermostRelease($this->lockPath, 1, function () use (&$calls) {
                    $calls[] = 'hook';
                });
            });

            // The nested frame ended, but the outermost lock is still held.
            $this->assertSame([], $calls);
        });

        $this->assertSame(['hook'], $calls);

        // A later lifecycle section must not re-run the drained hook.
        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, fn () => null);
        $this->assertSame(['hook'], $calls);
    }

    public function testReleaseHookIsIdempotentPerOwner(): void
    {
        $calls = 0;

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$calls) {
            LockManager::onOutermostRelease($this->lockPath, 42, function () use (&$calls) {
                $calls++;
            });
            LockManager::onOutermostRelease($this->lockPath, 42, function () use (&$calls) {
                $calls++;
            });
        });

        $this->assertSame(1, $calls);
    }

    public function testReleaseHooksOfDifferentOwnersAllRun(): void
    {
        $owners = [];

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$owners) {
            LockManager::onOutermostRelease($this->lockPath, 1, function () use (&$owners) {
                $owners[] = 1;
            });
            LockManager::onOutermostRelease($this->lockPath, 2, function () use (&$owners) {
                $owners[] = 2;
            });
        });

        $this->assertSame([1, 2], $owners);
    }

    public function testReleaseHookRunsAfterLockIsReleased(): void
    {
        $acquired = null;

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$acquired) {
            LockManager::onOutermostRelease($this->lockPath, 1, function () use (&$acquired) {
                // The flock must already be gone: a fresh exclusive
                // acquisition with a zero waiting budget can only succeed
                // if the shared lock was released before the hooks ran.
                $acquired = LockManager::withLifecycleLock($this->lockPath, LOCK_EX, 0.0, fn () => true);
            });
        });

        $this->assertTrue($acquired);
    }

    public function testReleaseHookExceptionsAreSwallowed(): void
    {
        $secondRan = false;

        LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$secondRan) {
            LockManager::onOutermostRelease($this->lockPath, 1, function () {
                throw new RuntimeException('hook failure');
            });
            LockManager::onOutermostRelease($this->lockPath, 2, function () use (&$secondRan) {
                $secondRan = true;
            });
        });

        $this->assertTrue($secondRan);
    }

    public function testCallbackExceptionPropagatesAndHooksStillRun(): void
    {
        $hookRan = false;

        try {
            LockManager::withLifecycleLock($this->lockPath, LOCK_SH, 1.0, function () use (&$hookRan) {
                LockManager::onOutermostRelease($this->lockPath, 1, function () use (&$hookRan) {
                    $hookRan = true;
                });

                throw new RuntimeException('callback failure');
            });
            $this->fail('Expected the callback exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failure', $exception->getMessage());
        }

        $this->assertTrue($hookRan);
    }

    public function testOnOutermostReleaseWithoutHeldLockThrows(): void
    {
        $this->expectException(LogicException::class);

        LockManager::onOutermostRelease($this->lockPath, 1, fn () => null);
    }

    public function testFlockWithTimeoutNegativeTimeoutBlocksUntilAcquired(): void
    {
        $stream = fopen($this->lockPath, 'c+');

        $this->assertTrue(LockManager::flockWithTimeout($stream, LOCK_EX, -1.0));

        flock($stream, LOCK_UN);
        fclose($stream);
    }
}
