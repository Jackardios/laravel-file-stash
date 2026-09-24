<?php

namespace Jackardios\FileStash\Facades;

use Illuminate\Support\Facades\Facade;
use Jackardios\FileStash\Testing\FileStashFake;

/**
 * @method static bool exists(\Jackardios\FileStash\Contracts\File $file)
 * @method static mixed get(\Jackardios\FileStash\Contracts\File $file, ?callable $callback = null, bool $throwOnLock = false)
 * @method static mixed getOnce(\Jackardios\FileStash\Contracts\File $file, ?callable $callback = null, bool $throwOnLock = false)
 * @method static mixed batch(\Jackardios\FileStash\Contracts\File[] $files, ?callable $callback = null, bool $throwOnLock = false)
 * @method static mixed batchOnce(\Jackardios\FileStash\Contracts\File[] $files, ?callable $callback = null, bool $throwOnLock = false)
 * @method static bool forget(\Jackardios\FileStash\Contracts\File $file)
 * @method static array{deleted: int, remaining: int, total_size: int, completed: bool} prune()
 * @method static void clear()
 * @method static \Jackardios\FileStash\Support\CacheMetrics metrics()
 *
 * @see \Jackardios\FileStash\FileStash
 */
class FileStash extends Facade
{
    /**
     * Swap the bound instance for a testing fake and return it.
     */
    public static function fake(): FileStashFake
    {
        static::swap($fake = new FileStashFake(static::getFacadeApplication()));

        return $fake;
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'file-stash';
    }
}
