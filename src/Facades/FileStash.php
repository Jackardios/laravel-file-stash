<?php

namespace Jackardios\FileStash\Facades;

use Jackardios\FileStash\Testing\FileStashFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool exists(\Jackardios\FileStash\Contracts\File $file)
 * @method static mixed get(\Jackardios\FileStash\Contracts\File $file, ?callable $callback = null, bool $throwOnLock = false)
 * @method static mixed getOnce(\Jackardios\FileStash\Contracts\File $file, ?callable $callback = null, bool $throwOnLock = false)
 * @method static mixed batch(\Jackardios\FileStash\Contracts\File[] $files, ?callable $callback = null, bool $throwOnLock = false)
 * @method static mixed batchOnce(\Jackardios\FileStash\Contracts\File[] $files, ?callable $callback = null, bool $throwOnLock = false)
 * @method static bool forget(\Jackardios\FileStash\Contracts\File $file)
 * @method static array prune()
 * @method static void clear()
 * @method static \Jackardios\FileStash\Support\CacheMetrics metrics()
 *
 * @see \Jackardios\FileStash\FileStash;
 */
class FileStash extends Facade
{
    /**
     * Use testing instance.
     */
    public static function fake(): void
    {
        static::swap(new FileStashFake(static::getFacadeApplication()));
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'file-stash';
    }
}
