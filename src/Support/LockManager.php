<?php

namespace Jackardios\FileStash\Support;

use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Low-level flock helpers shared by the cache implementation.
 */
final class LockManager
{
    /**
     * Minimum/maximum delay between lock attempts in microseconds (~50ms with jitter).
     */
    private const LOCK_RETRY_DELAY_MIN_US = 30000;

    private const LOCK_RETRY_DELAY_MAX_US = 70000;

    /**
     * Per-process registry of held lifecycle locks: lock path → LOCK_SH or
     * LOCK_EX. The lock streams stay local to the outermost frame; nested
     * compatible acquisitions never touch the registry.
     *
     * Shared between all FileStash instances in the process, so nested calls
     * against the same cache directory never self-deadlock. Note: the
     * registry (like flock ownership) does not survive pcntl_fork() into a
     * consistent state — do not fork while holding a lifecycle lock.
     *
     * @var array<string, int>
     */
    private static array $lifecycleRegistry = [];

    /**
     * Hooks to run once, after the outermost frame for a lock path has
     * released its flocks. Keyed by lock path, then by owner id, so
     * re-registering under the same owner stays idempotent.
     *
     * @var array<string, array<int, callable(): void>>
     */
    private static array $releaseHooks = [];

    /**
     * Try to acquire a flock, retrying non-blocking attempts until the timeout.
     *
     * The success check always happens before the deadline check, so a lock that
     * can be acquired immediately succeeds even with a timeout of 0.
     *
     * @param  resource  $stream
     * @param  int<0, 3>  $operation  LOCK_SH or LOCK_EX
     * @param  float  $timeout  Seconds to wait. Negative = wait indefinitely, 0 = single attempt.
     */
    public static function flockWithTimeout($stream, int $operation, float $timeout): bool
    {
        if ($timeout < 0) {
            // Indefinite wait: let the kernel block us instead of polling.
            // flock() can still return early on signal delivery (EINTR), so
            // it is retried until it succeeds.
            while (! flock($stream, $operation)) {
                usleep(random_int(self::LOCK_RETRY_DELAY_MIN_US, self::LOCK_RETRY_DELAY_MAX_US));
            }

            return true;
        }

        $startTime = microtime(true);

        while (true) {
            if (flock($stream, $operation | LOCK_NB)) {
                return true;
            }

            if ((microtime(true) - $startTime) >= $timeout) {
                return false;
            }

            usleep(random_int(self::LOCK_RETRY_DELAY_MIN_US, self::LOCK_RETRY_DELAY_MAX_US));
        }
    }

    /**
     * Execute a callback while holding the lifecycle lock.
     *
     * Reentrant within the process: nested acquisitions compatible with the
     * held lock (SH under SH, SH under EX, EX under EX) run the callback
     * under the outer frame's lock. A nested EX request under a held SH
     * (lock upgrade) is impossible with flock without releasing the SH first
     * and always throws LogicException — callers that must delete entries
     * from inside a shared section queue the work via onOutermostRelease().
     *
     * @template T
     *
     * @param  string  $lockPath  Lock file inside the cache directory.
     * @param  string|null  $legacyLockPath  Additional lock (always acquired
     *                                       first) for coexistence with workers using the old v4 lock
     *                                       location during rolling deploys.
     * @param  int<0, 3>  $lockType  LOCK_SH or LOCK_EX
     * @param  float  $timeout  Seconds to wait. Negative = wait indefinitely.
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LifecycleLockTimeoutException When the lock cannot be acquired in time.
     * @throws LogicException On a nested EX request under a held SH.
     */
    public static function withLifecycleLock(
        string $lockPath,
        ?string $legacyLockPath,
        int $lockType,
        float $timeout,
        callable $callback
    ) {
        $held = self::$lifecycleRegistry[$lockPath] ?? null;

        if ($held !== null) {
            if ($lockType === LOCK_EX && $held === LOCK_SH) {
                throw new LogicException(
                    'Cannot acquire an exclusive lifecycle lock while this process holds a shared one. '
                    .'Do not call clear() from inside batch()/batchOnce() callbacks.'
                );
            }

            return $callback();
        }

        $streams = self::acquireLifecycleStreams($lockPath, $legacyLockPath, $lockType, $timeout);
        self::$lifecycleRegistry[$lockPath] = $lockType;

        try {
            return $callback();
        } finally {
            unset(self::$lifecycleRegistry[$lockPath]);

            foreach (array_reverse($streams) as $stream) {
                flock($stream, LOCK_UN);
                fclose($stream);
            }

            // Hooks run AFTER the flocks are gone, so a hook may take a real
            // exclusive lifecycle lock (e.g. to flush deferred deletions).
            self::drainReleaseHooks($lockPath);
        }
    }

    /**
     * The lifecycle lock type this process currently holds for a lock path
     * (LOCK_SH or LOCK_EX), or null when none is held.
     */
    public static function heldLifecycleType(string $lockPath): ?int
    {
        return self::$lifecycleRegistry[$lockPath] ?? null;
    }

    /**
     * Register a hook to run once, right after the outermost lifecycle frame
     * for $lockPath releases its flocks.
     *
     * Idempotent per ($lockPath, $ownerId): re-registering replaces the
     * previous hook, so an owner queueing work incrementally fires once.
     *
     * @throws LogicException When no lifecycle lock is held for the path.
     */
    public static function onOutermostRelease(string $lockPath, int $ownerId, callable $hook): void
    {
        if (! isset(self::$lifecycleRegistry[$lockPath])) {
            throw new LogicException('Cannot register a release hook: no lifecycle lock is held for this path.');
        }

        self::$releaseHooks[$lockPath][$ownerId] = $hook;
    }

    /**
     * Run and clear the release hooks for a lock path.
     *
     * The hook list is detached before invocation, so hooks may safely
     * re-enter withLifecycleLock (registering new hooks starts a fresh
     * list). Hook failures are swallowed: a hook running inside the caller's
     * finally block must never mask the callback's own outcome.
     */
    private static function drainReleaseHooks(string $lockPath): void
    {
        $hooks = self::$releaseHooks[$lockPath] ?? [];
        unset(self::$releaseHooks[$lockPath]);

        foreach ($hooks as $hook) {
            try {
                $hook();
            } catch (Throwable) {
                // Hook owners handle/log their own errors.
            }
        }
    }

    /**
     * Acquire the lifecycle lock stream(s), legacy lock first.
     *
     * The shared deadline spans both acquisitions, so the configured timeout
     * bounds the whole operation.
     *
     * @param  int<0, 3>  $lockType
     * @return array<int, resource> Streams in acquisition order.
     *
     * @throws LifecycleLockTimeoutException
     * @throws RuntimeException
     */
    private static function acquireLifecycleStreams(
        string $lockPath,
        ?string $legacyLockPath,
        int $lockType,
        float $timeout
    ): array {
        $deadline = $timeout >= 0 ? microtime(true) + $timeout : null;
        $paths = $legacyLockPath !== null ? [$legacyLockPath, $lockPath] : [$lockPath];
        $streams = [];

        try {
            foreach ($paths as $path) {
                $stream = self::openLockStream($path);
                $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : -1.0;

                if (! self::flockWithTimeout($stream, $lockType, $remaining)) {
                    fclose($stream);
                    throw LifecycleLockTimeoutException::create(
                        "Failed to acquire file cache lifecycle lock within {$timeout} seconds."
                    );
                }

                $streams[] = $stream;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($streams) as $stream) {
                flock($stream, LOCK_UN);
                fclose($stream);
            }

            throw $exception;
        }

        return $streams;
    }

    /**
     * Open a lock file, creating its directory if needed.
     *
     * @return resource
     *
     * @throws RuntimeException
     */
    private static function openLockStream(string $path)
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
            if (! is_dir($directory)) {
                throw new RuntimeException("Failed to create lock directory '{$directory}'.");
            }
        }

        $stream = @fopen($path, 'c+');
        if ($stream === false) {
            throw new RuntimeException("Failed to open file cache lifecycle lock at '{$path}'.");
        }

        return $stream;
    }
}
