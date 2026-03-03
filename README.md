# Laravel File Stash

[![Tests](https://github.com/jackardios/laravel-file-stash/actions/workflows/tests.yml/badge.svg)](https://github.com/jackardios/laravel-file-stash/actions/workflows/tests.yml)

**Fetch and cache files from HTTP, cloud storage, or any Laravel disk — safely, even under heavy concurrency.**

---

## The Problem

You have queue workers processing jobs that need the same files — images, documents, exports. Each worker fetches its own copy. Downloads duplicate. Disk fills up. Workers corrupt each other's writes. Pruning deletes a file another worker is reading.

```
Worker A ──fetch──► image.jpg ──write──► /cache/abc123   ✗ corrupt
Worker B ──fetch──► image.jpg ──write──► /cache/abc123   ✗ overwrite
Worker C ──────────read───────────────► /cache/abc123   ✗ partial data
Pruner   ──────────delete─────────────► /cache/abc123   ✗ gone mid-read
```

## The Solution

File Stash gives every worker a safe, shared file cache with proper locking:

```
Worker A ──fetch──► image.jpg ──LOCK_EX──► /cache/abc123 ──unlock──► done
Worker B ────────── (waits) ──────────────LOCK_SH──► read ──► done
Worker C ────────── (waits) ──────────────LOCK_SH──► read ──► done
Pruner   ────────── (waits for all readers) ────────────────► prune
```

**One download. Shared reads. No corruption. No race conditions.**

---

## How It Works

```
                          ┌──────────────────────────────┐
                          │        File Stash Cache       │
                          │   /storage/cache/files/       │
                          │                               │
  ┌─────────────┐  get()  │  ┌────────┐   File exists?   │
  │  Worker 1   │────────►│  │ SHA-256 │──── yes ──► LOCK_SH ──► read ──► callback
  └─────────────┘         │  │  hash   │                  │
  ┌─────────────┐  get()  │  │         │──── no ───► fetch ──► LOCK_EX ──► write
  │  Worker 2   │────────►│  └────────┘              │    │
  └─────────────┘         │                          │    │
  ┌─────────────┐  get()  │     Sources:             │    │
  │  Worker 3   │────────►│     • https://...   ◄────┘    │
  └─────────────┘         │     • s3://...                │
                          │     • local://...             │
  ┌─────────────┐ prune() │                               │
  │  Scheduler  │────────►│  Lifecycle lock prevents      │
  └─────────────┘         │  pruning during batch ops     │
                          └──────────────────────────────┘
```

Each file is identified by a SHA-256 hash of its URL. The first worker to request a file fetches and caches it; subsequent workers read the cached copy through a shared lock. Pruning and batch operations coordinate via lifecycle locks so files are never deleted while in use.

---

## Installation

```bash
composer require jackardios/laravel-file-stash
```

The service provider and `FileStash` facade are auto-discovered.

Publish the config (optional):

```bash
php artisan vendor:publish --provider="Jackardios\FileStash\FileStashServiceProvider" --tag="config"
```

**Requirements:** PHP ^8.1, Laravel ^10 / ^11 / ^12

---

## Quick Start

### Cache a remote file

```php
use FileStash;
use Jackardios\FileStash\GenericFile;

$file = new GenericFile('https://example.com/reports/q4.pdf');

$result = FileStash::get($file, function ($file, $cachedPath) {
    // $cachedPath is a local path — read, copy, process, anything
    return Storage::put('reports/q4.pdf', file_get_contents($cachedPath));
});
```

### Cache a file from a Laravel storage disk

Any configured disk works — S3, GCS, SFTP, local:

```php
$file = new GenericFile('s3://bucket-exports/report.csv');

FileStash::get($file, function ($file, $path) {
    // Process the CSV from local cache
});
```

### Batch processing

Process multiple files while holding a lifecycle lock that prevents pruning:

```php
$files = [
    new GenericFile('https://cdn.example.com/img1.jpg'),
    new GenericFile('https://cdn.example.com/img2.jpg'),
    new GenericFile('https://cdn.example.com/img3.jpg'),
];

FileStash::batch($files, function ($files, $paths) {
    // All paths are guaranteed to exist for the duration of this callback
    // Pruner cannot delete them while you work
    foreach ($paths as $i => $path) {
        Image::make($path)->resize(300, 300)->save();
    }
});
```

### One-time files (auto-cleanup)

Files are deleted after the callback completes:

```php
FileStash::getOnce($file, function ($file, $path) {
    Mail::send([], [], fn ($m) => $m->attach($path));
});
// File is automatically removed from cache
```

---

## File Sources

URLs determine where files are fetched from:

| URL format | Source | Example |
|---|---|---|
| `https://...` or `http://...` | Remote HTTP via Guzzle | `https://cdn.example.com/photo.jpg` |
| `diskname://path` | Any Laravel filesystem disk | `s3://exports/data.csv` |

> Local paths like `/var/files/image.jpg` are not supported directly. Configure a [local disk](https://laravel.com/docs/filesystem#the-local-driver) and use `mydisk://image.jpg`.

---

## Concurrency Model

File Stash is designed for environments with multiple parallel queue workers processing the same files. Here's what happens under load:

### Concurrent reads — safe

```
Worker A ─── get("img.jpg") ──► LOCK_SH ──► read ──► unlock
Worker B ─── get("img.jpg") ──► LOCK_SH ──► read ──► unlock   (parallel, no waiting)
Worker C ─── get("img.jpg") ──► LOCK_SH ──► read ──► unlock
```

Multiple workers can read the same cached file simultaneously. Shared locks (`LOCK_SH`) do not block each other.

### Write during reads — safe

```
Worker A ─── get("new.jpg") ──► cache miss ──► LOCK_EX ──► download + write ──► unlock
Worker B ─── get("new.jpg") ──► cache miss ──► waits... ──────────────────────► LOCK_SH ──► read
```

If two workers request the same uncached file, one acquires the exclusive lock and fetches; the other waits, then reads the cached result.

### Batch + prune — safe

```
Worker  ─── batch([a, b, c]) ──► lifecycle lock (shared) ──► process ──► unlock
Pruner  ─── prune()           ──► waits for lifecycle lock ─────────────► prune
```

Batch operations hold a shared lifecycle lock. The pruner acquires an exclusive lifecycle lock, so it waits until all batch operations complete before deleting anything.

### Lock configuration

```php
// config/file-stash.php
'lock_max_attempts'      => 3,    // retries before giving up
'lock_wait_timeout'      => -1,   // seconds to wait (-1 = forever)
'lifecycle_lock_timeout' => 30,   // seconds for batch/prune coordination
```

For strict latency requirements, throw instead of waiting:

```php
use Jackardios\FileStash\Exceptions\FileLockedException;

try {
    FileStash::get($file, $callback, throwOnLock: true);
} catch (FileLockedException) {
    // File is busy, handle gracefully
}
```

---

## API Reference

### Core Methods

```php
// Cache and use a file
FileStash::get(File $file, ?callable $callback, bool $throwOnLock = false): mixed

// Cache, use, then delete
FileStash::getOnce(File $file, ?callable $callback, bool $throwOnLock = false): mixed

// Cache and use multiple files (prune-safe)
FileStash::batch(array $files, ?callable $callback, bool $throwOnLock = false): mixed

// Cache, use, then delete multiple files
FileStash::batchOnce(array $files, ?callable $callback, bool $throwOnLock = false): mixed
```

### Cache Management

```php
FileStash::exists(File $file): bool          // Check if a file's source exists
FileStash::forget(File $file): bool          // Remove a specific cached file
FileStash::prune(): array                     // Remove expired/oversized files
FileStash::clear(): void                      // Delete all unused cached files
FileStash::metrics(): CacheMetrics            // Get hit/miss/eviction counters
```

---

## Events

Enable event dispatching for observability:

```php
// config/file-stash.php
'events_enabled' => true,
```

| Event | When | Key properties |
|---|---|---|
| `CacheHit` | File served from cache | `$file`, `$cachedPath` |
| `CacheMiss` | File not cached, fetching | `$file`, `$url` |
| `CacheFileRetrieved` | File successfully cached | `$file`, `$cachedPath`, `$bytes`, `$source` |
| `CacheFileEvicted` | File deleted from cache | `$path`, `$reason` |
| `CachePruneCompleted` | Prune finished | `$deleted`, `$remaining`, `$totalSize`, `$completed` |

All events are in the `Jackardios\FileStash\Events` namespace.

When `events_enabled` is `false` (default), zero overhead — no objects allocated, no dispatching.

```php
use Jackardios\FileStash\Events\CacheHit;

Event::listen(CacheHit::class, function (CacheHit $event) {
    Log::info("Cache hit: {$event->file->getUrl()}");
});
```

---

## Metrics

Track cache effectiveness per-process:

```php
$metrics = FileStash::metrics();

$metrics->hits;        // cache hits
$metrics->misses;      // cache misses
$metrics->retrievals;  // files fetched from source
$metrics->evictions;   // files removed
$metrics->errors;      // failed retrievals

$metrics->hitRate();   // float|null — percentage
$metrics->toArray();   // all counters as array
$metrics->reset();     // zero out counters
```

Push to your monitoring stack:

```php
app()->terminating(function () {
    $m = FileStash::metrics()->toArray();
    // Send to Prometheus, StatsD, Datadog, etc.
});
```

---

## Configuration

All settings support environment variables. Publish the config to customize:

```bash
php artisan vendor:publish --provider="Jackardios\FileStash\FileStashServiceProvider" --tag="config"
```

### Cache limits

| Key | Env | Default | Description |
|---|---|---|---|
| `path` | — | `storage/framework/cache/files` | Cache directory |
| `max_file_size` | `FILE_STASH_MAX_FILE_SIZE` | `-1` (unlimited) | Max file size in bytes |
| `max_age` | `FILE_STASH_MAX_AGE` | `60` | TTL in minutes before pruning |
| `max_size` | `FILE_STASH_MAX_SIZE` | `1E+9` (1 GB) | Soft limit for total cache size |

### HTTP

| Key | Env | Default | Description |
|---|---|---|---|
| `timeout` | `FILE_STASH_TIMEOUT` | `-1` | Total request timeout (seconds) |
| `connect_timeout` | `FILE_STASH_CONNECT_TIMEOUT` | `30` | Connection timeout (seconds) |
| `read_timeout` | `FILE_STASH_READ_TIMEOUT` | `30` | Stream read timeout (seconds) |
| `http_retries` | `FILE_STASH_HTTP_RETRIES` | `0` | Retry attempts (4xx except 429 not retried) |
| `http_retry_delay` | `FILE_STASH_HTTP_RETRY_DELAY` | `100` | Base delay in ms (exponential backoff) |
| `user_agent` | `FILE_STASH_USER_AGENT` | `Laravel-FileStash/4.x` | User-Agent header |
| `max_redirects` | `FILE_STASH_MAX_REDIRECTS` | `5` | Max redirects to follow |

### Security

| Key | Env | Default | Description |
|---|---|---|---|
| `allowed_hosts` | `FILE_STASH_ALLOWED_HOSTS` | `null` (all) | Host whitelist for SSRF protection |
| `mime_types` | — | `[]` (all) | Allowed MIME types |

```php
// Wildcards supported
'allowed_hosts' => ['example.com', '*.cdn.example.com'],
```

```env
# Comma-separated in .env
FILE_STASH_ALLOWED_HOSTS=example.com,*.cdn.example.com
```

### Concurrency

| Key | Env | Default | Description |
|---|---|---|---|
| `lock_max_attempts` | `FILE_STASH_LOCK_MAX_ATTEMPTS` | `3` | Lock acquisition retries |
| `lock_wait_timeout` | `FILE_STASH_LOCK_WAIT_TIMEOUT` | `-1` (forever) | Lock wait timeout (seconds) |
| `lifecycle_lock_timeout` | `FILE_STASH_LIFECYCLE_LOCK_TIMEOUT` | `30` | Batch/prune coordination timeout |
| `batch_chunk_size` | `FILE_STASH_BATCH_CHUNK_SIZE` | `100` | Files per chunk (prevents fd exhaustion) |

### Pruning

| Key | Env | Default | Description |
|---|---|---|---|
| `prune_interval` | `FILE_STASH_PRUNE_INTERVAL` | `*/5 * * * *` | Cron schedule for auto-pruning |
| `prune_timeout` | `FILE_STASH_PRUNE_TIMEOUT` | `300` | Prune timeout (seconds) |

Prune manually or inspect results:

```php
$stats = FileStash::prune();
// ['deleted' => 12, 'remaining' => 48, 'total_size' => 524288000, 'completed' => true]
```

The cache is also cleared automatically when you run `php artisan cache:clear`.

### Performance

| Key | Env | Default | Description |
|---|---|---|---|
| `touch_interval` | `FILE_STASH_TOUCH_INTERVAL` | `60` | Min seconds between `touch()` on hot files |
| `events_enabled` | `FILE_STASH_EVENTS_ENABLED` | `false` | Enable event dispatching |

---

## Exceptions

All exceptions are in `Jackardios\FileStash\Exceptions` with `public readonly` properties for structured handling:

| Exception | When | Properties |
|---|---|---|
| `FileIsTooLargeException` | File exceeds `max_file_size` | `int $maxBytes` |
| `FileLockedException` | File locked, `throwOnLock` is `true` | — |
| `HostNotAllowedException` | Host not in `allowed_hosts` | `string $host` |
| `MimeTypeIsNotAllowedException` | MIME type not allowed | `string $mimeType` |
| `InvalidConfigurationException` | Invalid config value | `string $key`, `string $reason` |
| `SourceResourceIsInvalidException` | Invalid stream resource | — |
| `SourceResourceTimedOutException` | Stream read timed out | — |
| `FailedToRetrieveFileException` | All retries exhausted | — |

```php
use Jackardios\FileStash\Exceptions\HostNotAllowedException;

try {
    FileStash::get($file, $callback);
} catch (HostNotAllowedException $e) {
    Log::warning("Blocked: {$e->host}");
}
```

---

## Testing

The facade ships with a fake that skips real HTTP/disk operations:

```php
use FileStash;
use Jackardios\FileStash\GenericFile;

public function test_it_processes_file(): void
{
    FileStash::fake();

    $file = new GenericFile('https://example.com/image.jpg');

    $result = FileStash::get($file, fn ($file, $path) => 'processed');

    $this->assertEquals('processed', $result);
}
```

The fake supports all contract methods: `get`, `getOnce`, `batch`, `batchOnce`, `forget`, `exists`, `prune`, `clear`, `metrics`.

---

## Acknowledgements

This package is based on [biigle/laravel-file-cache](https://github.com/biigle/laravel-file-cache) by Martin Zurowietz. It extends the original with lifecycle locks, batch chunking, events, metrics, SSRF protection, HTTP retries, and other improvements.

---

## License

MIT