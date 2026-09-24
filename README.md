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
  │  Worker 1   │────────►│  │ SHA-256 │─ yes ► LOCK_SH ──► read ──► callback
  └─────────────┘         │  │  hash   │                  │
  ┌─────────────┐  get()  │  │         │─ no ─► claim ──► fetch ──► .tmp ──► rename
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

Each file is identified by a SHA-256 hash of its URL. On a cache miss the worker takes a per-file *claim lock* (so concurrent workers never download the same URL twice), streams the download into an exclusively locked temp file, and publishes it with an atomic `rename()`. Readers open the published file under a shared lock — they can never observe a partially written file. File-level locks prevent pruning of files that are currently in use, and lifecycle locks coordinate destructive operations like `clear()`. If a writer crashes mid-download, the kernel releases its locks and the next worker simply takes over; orphaned temp files are garbage-collected by `prune()`.

---

## Installation

```bash
composer require jackardios/laravel-file-stash
```

The service provider and `FileStash` facade are auto-discovered.

Publish the config (optional):

```bash
php artisan vendor:publish --tag=file-stash-config
```

**Requirements:** PHP ^8.3, Laravel ^12.61.1 / ^13.12, Guzzle ^7.15.2 / ^8.0.1, a local POSIX filesystem (Linux, macOS; Windows is best-effort, see [Known Limitations](#known-limitations)). On Laravel 10/11 or PHP 8.1/8.2 use `^4.0`.

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

> **Note:** the cache entry is shared by URL across all workers. `getOnce()`/`batchOnce()` delete that shared entry after the callback — if other workers use `get()` on the same URL, you are evicting their warm cache and forcing a re-download. Deletion is best effort and never waits for other workers' callbacks: it is skipped for files another worker is reading at that moment, and while a chunked batch of another worker runs it waits for the pin lock at most `lifecycle_lock_timeout`, then logs a warning — either way the callback result is still returned and the entry is left for `prune()`.

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

### Concurrent writes — safe

```
Worker A ─── get("new.jpg") ──► miss ──► claim ──► download ──► .tmp ──► rename ──► LOCK_SH ──► read
Worker B ─── get("new.jpg") ──► miss ──► waits on claim ──────────────────────────► LOCK_SH ──► read
```

If two workers request the same uncached file, one acquires the claim lock and downloads; the other waits on the claim, then reads the published result. The download streams into a temp file (`{hash}.{pid}.{random}.tmp`) that is exclusively locked for its whole lifetime and published with an atomic `rename()` — a reader can never open a partially written file. If the writer crashes, the kernel releases the claim and the waiting worker downloads the file itself.

### Batch + prune

```
Worker  ─── batch([a, b, c]) ──► LOCK_SH on each file ──► callback ──► unlock
Pruner  ─── prune()          ──► tries LOCK_EX on file ──► skipped (file is busy)
```

While your callback runs, each cached file is held with a shared lock (`LOCK_SH`). The pruner tries to acquire an exclusive lock (`LOCK_EX`) before deleting — if it can't, it skips the file.

> **Chunked batches.** When a batch contains more files than `batch_chunk_size` (default 100), files are retrieved chunk by chunk and their shared locks are released after each chunk (this prevents file descriptor exhaustion). Instead, a chunked batch holds a shared **pin lock** (`.pin.lock` in the cache directory) from the first chunk until the callback returns: while any chunked batch runs, `prune()` evicts nothing (it reports `completed => false` and catches up on its next run), and `forget()` and the `getOnce()`/`batchOnce()` cleanup of other workers wait for it (at most `lifecycle_lock_timeout`). `clear()` is excluded by the lifecycle lock as usual. Keep chunked batches short — a batch that runs for hours postpones eviction for as long.

`clear()` goes further — it acquires an exclusive lifecycle lock, so it waits until all `batch()`/`get()` operations finish before deleting anything.

### Nested calls

Cache calls may be nested (e.g. `get()` inside a `batch()` callback) — the lifecycle lock is reentrant within a process. Two rules apply:

- `forget()`, `getOnce()`, or `batchOnce()` inside a `batch()`/`batchOnce()` callback: the entry may still be in use by the callback, so the deletion is **deferred** — the entry stays on disk for the whole callback and is deleted right after the outermost batch releases its shared lifecycle lock. `forget()` returns `true` in that case, meaning "scheduled for deletion". Note the flush happens once per outermost batch: if a later chunk of the same batch re-downloads a forgotten entry, the flush removes the fresh copy too.
- `clear()` inside a `batch()`/`batchOnce()` callback throws a `LogicException` immediately instead of deadlocking.

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

### Sharing the cache between users

Files and directories are created with the default modes (`0666`/`0777`) minus the process umask. When the web server and the queue workers run as different users, give them a common group, make the cache directory group-writable with the setgid bit (new entries inherit the group), and run every process with a group-writable umask:

```bash
mkdir -p storage/framework/cache/files
chgrp www-data storage/framework/cache/files
chmod 2775 storage/framework/cache/files
```

The umask is inherited from the process manager: e.g. `UMask=0002` in a systemd drop-in for the php-fpm service, `umask=002` in each supervisor `[program:...]` section.

Lock files created by another user without write permission for you are opened read-only (`flock()` works on read-only descriptors), but new entries still need a writable directory.

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
FileStash::exists(File $file): bool          // Check if a file exists at its SOURCE (not in the cache)
FileStash::forget(File $file): bool          // Remove a cached file (inside a batch callback: schedules the deletion; see Nested calls)
FileStash::prune(): array                     // Remove expired/oversized files
FileStash::clear(): void                      // Delete all unused cached files
FileStash::metrics(): CacheMetrics            // Get hit/miss/eviction counters
```

`exists()` returns `false` only for a definitive answer — a 4xx other than 429, or a redirect it did not follow to a document. A 429, a 5xx, or a network error (after `http_retries`) throws (`FailedToRetrieveFileException` with `statusCode`, or the Guzzle exception): it says nothing about whether the file exists.

With a `mime_types` whitelist, `exists()` checks the type the source reports (the `Content-Type` header, or the disk's type, which may be guessed from the file extension), while `get()` checks the downloaded content. A file `exists()` accepted can still be rejected by `get()`, for example a `.csv` whose content is detected as `text/plain`.

---

## Pruning

Pruning removes cached files that are older than `max_age` or exceed the `max_size` limit.

### Automatic pruning

The service provider registers a scheduled command that runs automatically. By default, it prunes every 5 minutes:

```php
// config/file-stash.php
'prune_interval' => '*/5 * * * *',   // set to null to disable scheduled pruning
```

Make sure Laravel's scheduler is running:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Manual pruning

Run the artisan command:

```bash
php artisan file-stash:prune
```

(The old `prune-file-stash` name still works as a deprecated alias and will be removed in v6.)

Or call it programmatically:

```php
$stats = FileStash::prune();
// ['deleted' => 12, 'remaining' => 48, 'total_size' => 524288000, 'completed' => true]
```

### Clearing all cache

```php
FileStash::clear();           // Delete all unused cached files
FileStash::forget($file);     // Remove a specific file (true = deleted or scheduled)
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
| `CacheMiss` | File not cached, fetching | `$file` |
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
php artisan vendor:publish --tag=file-stash-config
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
| `timeout` | `FILE_STASH_TIMEOUT` | `300` | Total request timeout (seconds, `-1`/`0` = unlimited) |
| `connect_timeout` | `FILE_STASH_CONNECT_TIMEOUT` | `30` | Connection timeout (seconds); `-1`/`0` = curl's built-in 300 s |
| `read_timeout` | `FILE_STASH_READ_TIMEOUT` | `30` | Stall timeout (seconds, see below; `-1`/`0` = unlimited) |
| `http_retries` | `FILE_STASH_HTTP_RETRIES` | `0` | Retry attempts (4xx except 429 not retried) |
| `http_retry_delay` | `FILE_STASH_HTTP_RETRY_DELAY` | `100` | Base delay in ms (exponential backoff) |
| `user_agent` | `FILE_STASH_USER_AGENT` | `Laravel-FileStash/5.x` | User-Agent header |
| `max_redirects` | `FILE_STASH_MAX_REDIRECTS` | `5` | Max redirects to follow |

**How `read_timeout` works:** for HTTP(S) sources it maps to curl's low-speed abort — the transfer fails when it stalls below 1 byte/s for `read_timeout` seconds (rounded up to whole seconds). HTTP timeouts surface as Guzzle exceptions (`ConnectException`/`RequestException`), which participate in `http_retries`. For storage-disk streams it is applied via `stream_set_timeout()` and a stalled read throws `SourceResourceTimedOutException`.

### Security

> **⚠️ SSRF protection is OFF by default.** If URLs come from user input, configure `allowed_hosts` and/or enable `block_private_hosts` — otherwise users can make your workers fetch internal endpoints (cloud metadata services, private APIs, etc.). Restrict `allowed_disks` as well: a `disk://path` URL reads from **any** configured storage disk by default (`local://.env`, a private S3 bucket, …).

| Key | Env | Default | Description |
|---|---|---|---|
| `allowed_hosts` | `FILE_STASH_ALLOWED_HOSTS` | `null` (all allowed) | Host whitelist for SSRF protection |
| `allowed_disks` | `FILE_STASH_ALLOWED_DISKS` | `null` (all allowed) | Storage disks `disk://path` URLs may read; `[]` = HTTP(S) only |
| `block_private_hosts` | `FILE_STASH_BLOCK_PRIVATE_HOSTS` | `false` | Reject private/loopback/link-local addresses |
| `mime_types` | — | `[]` (all) | Allowed MIME types (compared case-insensitively, `; charset=…` parameters ignored) |

`allowed_hosts` semantics — read carefully:

- `null` or `''` — **all hosts allowed** (the default!)
- `[]` (empty array) — **all remote hosts blocked**
- a list — only the listed hosts allowed; redirect targets are validated too

```php
// Wildcards supported; '*.cdn.example.com' also matches 'cdn.example.com' itself
'allowed_hosts' => ['example.com', '*.cdn.example.com'],
```

```env
# Comma-separated in .env
FILE_STASH_ALLOWED_HOSTS=example.com,*.cdn.example.com
```

`block_private_hosts` rejects IP literals from the special-purpose IPv4/IPv6 ranges — private, loopback, link-local, CGNAT `100.64/10` (cloud metadata services live there), benchmarking, TEST-NETs, multicast, reserved, NAT64 `64:ff9b::/96`, Teredo, 6to4 `2002::/16` (blanket-denied), every IPv6 address outside the global unicast space `2000::/3` (ULA, link-local, site-local, IPv4-compatible and SIIT forms, reserved space), and v4-mapped IPv6 (checked by the IPv4 rules). Hostnames are resolved via DNS **and** the hosts file (A and AAAA records; all resolved addresses are checked), and hosts that resolve to nothing are rejected (fail closed). It cannot protect against DNS rebinding, because curl resolves the hostname again for the actual request; use `allowed_hosts` as the primary defense.

`allowed_disks` follows the same `null` / `''` / `[]` / list semantics (names are compared exactly) and rejects other disks with `DiskNotAllowedException`, a subclass of `HostNotAllowedException`:

```php
'allowed_disks' => ['uploads'],   // only uploads://path; other disks are rejected
```

IPv6 literals — in URLs and in `allowed_hosts` — are canonicalized before comparison, so `https://[2001:DB8::0001]/…` matches a whitelisted `2001:db8::1`. A non-empty `allowed_hosts` value that parses to zero hosts (a stray `','`, whitespace-only entries) throws `InvalidConfigurationException`; blocking all remote hosts requires an explicit empty array.

### Concurrency

| Key | Env | Default | Description |
|---|---|---|---|
| `lock_max_attempts` | `FILE_STASH_LOCK_MAX_ATTEMPTS` | `3` | Lock acquisition retries |
| `lock_wait_timeout` | `FILE_STASH_LOCK_WAIT_TIMEOUT` | `-1` (forever) | Lock wait timeout (seconds) |
| `lifecycle_lock_timeout` | `FILE_STASH_LIFECYCLE_LOCK_TIMEOUT` | `30` | Batch/clear coordination timeout |
| `batch_chunk_size` | `FILE_STASH_BATCH_CHUNK_SIZE` | `100` | Files per chunk (prevents fd exhaustion; `-1` = no chunking) |

### Pruning

| Key | Env | Default | Description |
|---|---|---|---|
| `prune_interval` | `FILE_STASH_PRUNE_INTERVAL` | `*/5 * * * *` | Cron schedule for auto-pruning (`null` disables it; an invalid expression is reported and the task skipped) |
| `prune_timeout` | `FILE_STASH_PRUNE_TIMEOUT` | `300` | Prune timeout (seconds); `-1`/`0` = no timeout |

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
| `DiskNotAllowedException` | Disk not in `allowed_disks` (extends `HostNotAllowedException`) | `string $disk` (also in `$host`) |
| `MimeTypeIsNotAllowedException` | MIME type not allowed | `string $mimeType` |
| `InvalidConfigurationException` | Invalid config value | `string $key`, `string $reason` |
| `SourceResourceIsInvalidException` | Invalid stream resource | — |
| `SourceResourceTimedOutException` | Storage-disk stream read timed out | — |
| `FailedToRetrieveFileException` | All retries exhausted | `int $statusCode` (`0` if not HTTP) |
| `LifecycleLockTimeoutException` | Lifecycle or pin lock not acquired within `lifecycle_lock_timeout` (extends `RuntimeException`; `forget()` catches it and returns `false`) | — |

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

The facade ships with a fake that skips real HTTP/disk operations but still hands your callbacks **real local files**, so code that reads the file (Imagick, PhpSpreadsheet, `hash_file()`, …) works in tests:

```php
use FileStash;
use Jackardios\FileStash\GenericFile;

public function test_it_processes_file(): void
{
    $fake = FileStash::fake();

    // Optional: control the file's content (default is deterministic per URL)
    $fake->putFake('https://example.com/image.jpg', 'fake image bytes');

    $file = new GenericFile('https://example.com/image.jpg');

    $result = FileStash::get($file, fn ($file, $path) => file_get_contents($path));

    $this->assertEquals('fake image bytes', $result);
    $fake->assertRetrieved('https://example.com/image.jpg');
}
```

The fake supports all contract methods (`get`, `getOnce`, `batch`, `batchOnce`, `forget`, `exists`, `prune`, `clear`, `metrics`) plus test helpers:

```php
$fake->putFake($url, $content);          // seed a file with specific content
$fake->shouldExist($url, false);         // control what exists() reports (default: true after putFake() or while a retrieved file is cached)
$fake->path();                           // the fake's temp directory

$fake->assertRetrieved($url);            // get/getOnce/batch/batchOnce was called for the URL
$fake->assertRetrievedTimes($url, 3);
$fake->assertNotRetrieved($url);
$fake->assertNothingRetrieved();
$fake->assertForgotten($url);            // forget() was called for the URL
```

The fake is hermetic: it never downloads anything and never reads storage disks, so `disk://` URLs get fake content too, and `exists()` does not look at `Storage::fake()` disks — use `putFake()`/`shouldExist()`. Metrics are tracked and `getOnce()`/`batchOnce()` really delete their files, so eviction-sensitive code paths behave like production. As in the real cache, `forget()` and `getOnce()` called inside a batch callback delete the file only after the outermost batch returns.

`FileStashFake` extends the real `FileStash`, so `FileStash::fake()` also works for code that type-hints (or resolves from the container) the concrete class. Prefer the `Jackardios\FileStash\Contracts\FileStash` contract in your own typehints anyway — it keeps your code decoupled from the implementation. Like `Storage::fake()`, the fake works in a stable directory under `storage/framework/testing` (suffixed with the parallel-testing token when running `php artisan test --parallel`) that is wiped every time a fake is constructed.

---

## Known Limitations

- **Local filesystem only.** All guarantees are built on `flock()`, atomic `rename()`, and inode semantics of a local POSIX filesystem. Do **not** point `path` at NFS or other network mounts — advisory locking there ranges from unreliable to silently broken. In multi-server setups give each server its own cache directory.
- **flock has no fairness.** `clear()` waits for an exclusive lifecycle lock, which every running `get()`/`batch()` holds shared, and can be starved by a continuous stream of them; deletions (`forget()`, the once-cleanup, `prune()`) can be starved the same way by overlapping chunked batches. `lifecycle_lock_timeout` bounds the wait; design hot paths so `clear()` isn't racing them constantly.
- **Chunked batches pause eviction** — while one runs, `prune()` deletes nothing; see [Batch + prune](#batch--prune).
- **`block_private_hosts` cannot stop DNS rebinding** — curl re-resolves the hostname for the actual request. Use `allowed_hosts` as the primary SSRF defense.
- **Windows is best-effort.** Downloads, MIME checks and locking work, and CI runs the fast suite on Windows, but the concurrency guarantees are only verified on POSIX systems. NTFS keeps a deleted file visible until its last handle closes, so the checks that detect an entry deleted or replaced under a reader do not fire, and `rename()` over an entry another process holds open can fail after its retries.
- **Octane** runs every request in a clone of the application, so the `file-stash` singleton is built per request and `metrics()` covers that request only — unless you add `file-stash` to `octane.warm`, which shares one instance (and its metrics) across the worker's requests. Either way is safe: the instance holds no request state, and the lock registry is empty again after every call.
- **`pcntl_fork()`**: the lifecycle-lock reentrancy registry is per process. A child forked while the parent holds a lifecycle lock shares the lock file descriptor with unpredictable results — don't fork mid-callback.

---

## Upgrading from v4

See [UPGRADE.md](UPGRADE.md). Projects on Laravel 10/11 or PHP 8.1/8.2 stay on `^4.0`.

---

## Acknowledgements

This package is based on [biigle/laravel-file-cache](https://github.com/biigle/laravel-file-cache) by Martin Zurowietz. It extends the original with lifecycle locks, batch chunking, events, metrics, SSRF protection, HTTP retries, and other improvements.

---

## License

MIT
