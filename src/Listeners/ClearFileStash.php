<?php

namespace Jackardios\FileStash\Listeners;

use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use Psr\Log\LoggerInterface;

/**
 * Clears the file stash on `cache:clear` (the `cache:clearing` event).
 */
class ClearFileStash
{
    public function __construct(
        protected FileStashContract $cache,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Handle the event.
     *
     * @param  string|null  $store  The cache store being cleared.
     * @param  array<int, string>  $tags  The tags of a tag-scoped clear.
     */
    public function handle(?string $store = null, array $tags = []): void
    {
        // `cache:clear --tags=...` flushes only the tagged items of a store.
        if ($tags !== []) {
            return;
        }

        try {
            $this->cache->clear();
        } catch (LifecycleLockTimeoutException $exception) {
            // A running batch must not make `cache:clear` (or
            // `optimize:clear`) fail for the application cache as well.
            $this->logger->warning('Could not clear the file stash: the lifecycle lock is busy.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
