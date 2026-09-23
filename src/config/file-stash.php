<?php

return [

    /*
    | Maximum allowed size of a cached file in bytes. Set to -1 to allow any size.
    */
    'max_file_size' => env('FILE_STASH_MAX_FILE_SIZE', -1),

    /*
    | Maximum age in minutes of a file in the cache. Older files are pruned.
    */
    'max_age' => env('FILE_STASH_MAX_AGE', 60),

    /*
    | Maximum size (soft limit) of the file cache in bytes. If the cache exceeds
    | this size, old files are pruned.
    */
    'max_size' => env('FILE_STASH_MAX_SIZE', 1_000_000_000), // 1 GB

    /*
    | Directory to use for the file cache.
    */
    'path' => storage_path('framework/cache/files'),

    /*
     | Maximum number of attempts to set a lock on a file.
     | Must be at least 1.
     | Default: 3
     */
    'lock_max_attempts' => env('FILE_STASH_LOCK_MAX_ATTEMPTS', 3),

    /*
     | Timeout to wait for a lock on a file to be released in seconds.
     | Set to -1 to wait indefinitely.
     | Default: -1 (indefinitely)
     */
    'lock_wait_timeout' => env('FILE_STASH_LOCK_WAIT_TIMEOUT', -1),

    /*
     | Total timeout for downloading a remote file in seconds.
     | If loading the file takes longer than this, it will fail.
     | Set to -1 to wait indefinitely.
     | Default: 300 seconds (5 minutes)
     */
    'timeout' => env('FILE_STASH_TIMEOUT', 300),

    /*
     | Timeout to initiate a connection to load a remote file in seconds.
     | If it takes longer, it will fail. Set to -1 to wait indefinitely.
     | Default: 30 seconds
     */
    'connect_timeout' => env('FILE_STASH_CONNECT_TIMEOUT', 30.0),

    /*
     | Stall timeout for reading file data in seconds.
     | For HTTP(S) sources this maps to curl's low-speed abort: the transfer
     | fails when it stalls below 1 byte/s for this many seconds (whole
     | seconds, rounded up). For storage-disk streams it is applied via
     | stream_set_timeout(). Set to -1 to wait indefinitely.
     | Default: 30 seconds
     */
    'read_timeout' => env('FILE_STASH_READ_TIMEOUT', 30.0),

    /*
     | Cron expression for the scheduled `file-stash:prune` task.
     | Set to null to disable the scheduled prune entirely.
     */
    'prune_interval' => env('FILE_STASH_PRUNE_INTERVAL', '*/5 * * * *'),

    /*
     | Timeout for the prune operation in seconds.
     | If pruning takes longer than this, it will stop early.
     | Set to -1 for no timeout.
     | Default: 300 seconds (5 minutes)
     */
    'prune_timeout' => env('FILE_STASH_PRUNE_TIMEOUT', 300),

    /*
     | Allowed MIME types for cached files. Fetching of files with any other type fails.
     | This is especially useful for files from a remote source. Leave empty to allow all
     | types.
     |
     | Comparison is case-insensitive and ignores parameters ("text/plain;
     | charset=utf-8" matches 'text/plain'). Files whose type cannot be
     | detected — including empty files — are always rejected when a
     | whitelist is configured (deny by default).
     */
    'mime_types' => [],

    /*
     | Allowed hosts for remote file fetching. This is a security feature to
     | prevent SSRF (Server-Side Request Forgery) attacks.
     |
     | IMPORTANT semantics:
     |   - null or ''  => ALL hosts are allowed (no restriction; the default!)
     |   - []          => ALL remote hosts are blocked
     |   - array/comma-separated string => only the listed hosts are allowed
     |
     | Example: ['example.com', 'cdn.example.com', '*.trusted-domain.com']
     | Wildcards (*.) are supported at the beginning of hostnames and also
     | match the root domain itself. IPv6 literals are canonicalized before
     | comparison. A non-empty value that parses to zero hosts (e.g. ',')
     | throws InvalidConfigurationException — blocking all remote hosts
     | requires an explicit [].
     | Default: null (all hosts allowed)
     */
    'allowed_hosts' => env('FILE_STASH_ALLOWED_HOSTS', null),

    /*
     | Block requests to private and other special-purpose addresses (SSRF
     | protection). IP literals from the reserved IPv4/IPv6 ranges are
     | rejected — private, loopback, link-local, CGNAT (cloud metadata),
     | benchmarking, TEST-NETs, multicast, NAT64, Teredo, 6to4, ULA, and
     | v4-mapped IPv6. Hostnames are resolved via DNS and the hosts file
     | (A and AAAA records; all resolved addresses are checked), and hosts
     | that resolve to nothing are rejected. Redirect targets are validated
     | as well. Note: this cannot protect against DNS rebinding, because
     | curl resolves the hostname again for the actual request.
     | Default: false
     */
    'block_private_hosts' => env('FILE_STASH_BLOCK_PRIVATE_HOSTS', false),

    /*
     | Number of retry attempts for failed HTTP requests.
     | Set to 0 to disable retries.
     | Default: 0
     */
    'http_retries' => env('FILE_STASH_HTTP_RETRIES', 0),

    /*
     | Delay between HTTP retry attempts in milliseconds.
     | Default: 100
     */
    'http_retry_delay' => env('FILE_STASH_HTTP_RETRY_DELAY', 100),

    /*
     | Timeout to wait for lifecycle lock acquisition in seconds.
     | This lock coordinates batch/batchOnce with prune/clear operations.
     | On timeout, batch/batchOnce/prune/clear throw
     | LifecycleLockTimeoutException; forget() logs a warning and returns
     | false. Set to -1 to wait indefinitely.
     | Default: 30 seconds
     */
    'lifecycle_lock_timeout' => env('FILE_STASH_LIFECYCLE_LOCK_TIMEOUT', 30.0),

    /*
     | Maximum number of files to process in a single chunk during
     | batch() and batchOnce() to avoid file descriptor exhaustion.
     | Set to -1 for no limit.
     | Default: 100
     */
    'batch_chunk_size' => env('FILE_STASH_BATCH_CHUNK_SIZE', 100),

    /*
     | User-Agent header sent with HTTP requests to remote servers.
     | Default: 'Laravel-FileStash/5.x'
     */
    'user_agent' => env('FILE_STASH_USER_AGENT', 'Laravel-FileStash/5.x'),

    /*
     | Maximum number of HTTP redirects to follow.
     | Default: 5
     */
    'max_redirects' => env('FILE_STASH_MAX_REDIRECTS', 5),

    /*
     | Minimum interval in seconds between touch() calls on cached files.
     | Reduces filesystem writes on frequently accessed files.
     | Set to 0 to touch on every access.
     | Default: 60 seconds
     */
    'touch_interval' => env('FILE_STASH_TOUCH_INTERVAL', 60),

    /*
     | Enable dispatching of cache events (CacheHit, CacheMiss, etc.).
     | When disabled, no events are dispatched (zero overhead).
     | Default: false
     */
    'events_enabled' => env('FILE_STASH_EVENTS_ENABLED', false),

];
