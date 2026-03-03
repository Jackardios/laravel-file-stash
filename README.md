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
Pruner   ────────── (skips locked files) ────────────────────► prune
```

**One download. Shared reads. No corruption. No race conditions.**

---

## When Is This Useful?

File Stash helps when your application needs to **download a file and process it locally** — not just move it between storages, but actually do something with the bytes:

- **Image processing pipelines** — resize, watermark, or convert images from S3/CDN across multiple queue workers
- **PDF processing** — extract text, merge, or convert documents from cloud storage
- **ML/AI workers** — load model weights or input data from cloud storage, process locally
- **Video/audio processing** — extract thumbnails, transcode, analyze media files
- **Data import jobs** — parse CSV/Excel files from external sources in parallel workers
- **Email attachments** — fetch, attach, and discard temporary files

In all these cases, `Storage::get()` returns file contents as a string (loaded into memory). File Stash gives you a **local file path** you can pass to any tool — FFmpeg, Imagick, Python scripts, shell commands — without holding the entire file in PHP memory.

---

## How It Works

```
                          ┌───────────────────────────────┐
                          │        File Stash Cache       │
                          │   /storage/cache/files/       │
                          │                               │
  ┌─────────────┐  get()  │  ┌─────────┐   File exists?   │
  │  Worker 1   │────────►│  │ SHA-256 │──── yes ──► LOCK_SH ──► read ──► callback
  └─────────────┘         │  │  hash   │                  │
  ┌─────────────┐  get()  │  │         │──── no ───► fetch ──► LOCK_EX ──► write
  │  Worker 2   │────────►│  └─────────┘             │    │
  └─────────────┘         │                          │    │
  ┌─────────────┐  get()  │     Sources:             │    │
  │  Worker 3   │────────►│     • https://...   ◄────┘    │
  └─────────────┘         │     • s3://...                │
                          │     • local://...             │
  ┌─────────────┐ prune() │                               │
  │  Scheduler  │────────►│  File-level locks prevent     │
  └─────────────┘         │  deletion of files in use     │
                          └───────────────────────────────┘
```

Each file is identified by a SHA-256 hash of its URL. The first worker to request a file fetches and caches it; subsequent workers read the cached copy through a shared lock. File-level locks prevent pruning of files that are currently in use, and lifecycle locks coordinate destructive operations like `clear()`.

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

### Process a remote file locally

```php
use FileStash;
use Jackardios\FileStash\GenericFile;

$file = new GenericFile('https://cdn.example.com/uploads/photo.jpg');

FileStash::get($file, function ($file, $cachedPath) {
    // $cachedPath is a real local path — pass it to any tool
    $image = Image::read($cachedPath);
    $image->resize(300, 200)->save(storage_path('thumbs/photo.jpg'));
});
```

### Process a file from a Laravel storage disk

Any configured disk works — S3, GCS, SFTP, local. The prefix before `://` is the disk name from `config/filesystems.php`:

```php
// "reports" is the disk name from config/filesystems.php
$file = new GenericFile('reports://exports/sales-2024.xlsx');

FileStash::get($file, function ($file, $path) {
    // $path is a local file — pass it to any library that needs a file path
    $spreadsheet = IOFactory::load($path);
    $data = $spreadsheet->getActiveSheet()->toArray();
    // Process $data...
});
```

> **Note:** `reports://` refers to a Laravel disk named `reports`, not a URL protocol. Configure your disks in `config/filesystems.php`.

### Batch processing

Process multiple files — each is held with a shared lock so nothing gets pruned or corrupted during the callback:

```php
$attachments = Attachment::where('report_id', $reportId)->get();

$files = $attachments->map(
    fn ($a) => new GenericFile($a->storage_url)
)->all();

FileStash::batch($files, function ($files, $paths) {
    // All files are locked for reading — safe from pruning and deletion
    $zip = new ZipArchive();
    $zip->open(storage_path('app/export.zip'), ZipArchive::CREATE);
    foreach ($paths as $i => $path) {
        $zip->addFile($path, basename($files[$i]->getUrl()));
    }
    $zip->close();
});
```

### One-time files (auto-cleanup)

Use `getOnce()` when you only need the file temporarily — it's deleted from cache after the callback:

```php
$invoice = new GenericFile('https://billing.example.com/invoices/INV-2024-001.pdf');

FileStash::getOnce($invoice, function ($file, $path) {
    // Send email with attachment, then discard the cached file
    Mail::to('user@example.com')->send(new InvoiceMail($path));
});
// Cached file is automatically removed from disk
```

---

## Custom File Implementations

`GenericFile` works for simple cases. For domain models, implement the `File` interface directly on your Eloquent model:

```php
use Jackardios\FileStash\Contracts\File;

class Document extends Model implements File
{
    public function getUrl(): string
    {
        // Remote URL
        return $this->cdn_url;

        // Or a Laravel disk path
        // return "documents://{$this->path}";
    }
}
```

Then pass models directly:

```php
$document = Document::find(1);

FileStash::get($document, function ($document, $path) {
    // $document is your Eloquent model — access any attribute
    $text = (new PdfParser())->parseFile($path)->getText();
    $document->update(['extracted_text' => $text]);
});
```

---

## File Sources

The URL prefix determines where the file is fetched from:

| URL format | Source | Example |
|---|---|---|
| `https://...` or `http://...` | Remote HTTP via Guzzle | `https://cdn.example.com/photo.jpg` |
| `diskname://path` | Laravel filesystem disk | `photos://uploads/photo.jpg` |

The `diskname` must match a key in your `config/filesystems.php` `disks` array.

> Local file paths like `/var/files/image.jpg` are not supported directly. Configure a [local disk](https://laravel.com/docs/filesystem#the-local-driver) and use `mydisk://image.jpg`.

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
Worker  ─── batch([a, b, c]) ──► LOCK_SH on each file ──► callback ──► unlock
Pruner  ─── prune()          ──► tries LOCK_EX on file ──► skipped (file is busy)
```

While your callback runs, each cached file is held with a shared lock (`LOCK_SH`). The pruner tries to acquire an exclusive lock (`LOCK_EX`) before deleting — if it can't, it skips the file. Your files are safe for the entire duration of the callback.

`clear()` goes further — it acquires an exclusive lifecycle lock, so it waits until all `batch()`/`get()` operations finish before deleting anything.

### Lock configuration

```php
// config/file-stash.php
'lock_max_attempts'      => 3,    // retries before giving up
'lock_wait_timeout'      => -1,   // seconds to wait (-1 = forever)
'lifecycle_lock_timeout' => 30,   // seconds for batch/clear coordination
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

## Pruning

Pruning removes cached files that are older than `max_age` or exceed the `max_size` limit.

### Automatic pruning

The service provider registers a scheduled command that runs automatically. By default, it prunes every 5 minutes:

```php
// config/file-stash.php
'prune_interval' => '*/5 * * * *',
```

Make sure Laravel's scheduler is running:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Manual pruning

Run the artisan command:

```bash
php artisan prune-file-stash
```

Or call it programmatically:

```php
$stats = FileStash::prune();
// ['deleted' => 12, 'remaining' => 48, 'total_size' => 524288000, 'completed' => true]
```

### Clearing all cache

```php
FileStash::clear();           // Delete all unused cached files
FileStash::forget($file);     // Remove a specific file
```

The cache is also cleared automatically when you run `php artisan cache:clear`.

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
| `lifecycle_lock_timeout` | `FILE_STASH_LIFECYCLE_LOCK_TIMEOUT` | `30` | Batch/clear coordination timeout |
| `batch_chunk_size` | `FILE_STASH_BATCH_CHUNK_SIZE` | `100` | Files per chunk (prevents fd exhaustion) |

### Pruning

| Key | Env | Default | Description |
|---|---|---|---|
| `prune_interval` | `FILE_STASH_PRUNE_INTERVAL` | `*/5 * * * *` | Cron schedule for auto-pruning |
| `prune_timeout` | `FILE_STASH_PRUNE_TIMEOUT` | `300` | Prune timeout (seconds) |

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
