# Changelog

All notable changes to this package are documented in this file.

## v5.0.0 — 2026-07-25

Major rewrite of the concurrency core. For Laravel 10 / PHP 8.1 stay on v4.x.
See the [Upgrading v4 → v5](README.md#upgrading-v4--v5) section of the README
for the full migration guide, including how to switch workers over.

### Breaking: requirements

- PHP `^8.3` (was `^8.1`); CI covers PHP 8.3–8.5.
- Laravel `^12 || ^13` (was `^10 || ^11 || ^12`). Laravel 11 left its
  security-fix window before this release and every 11.x version is affected
  by known security advisories, so it is not supported.
- Dependency floors are the oldest versions without known security
  advisories (Composer 2.9+ refuses to install insecure versions by
  default): `illuminate/* ^12.61.1 || ^13.12`, `guzzlehttp/guzzle ^7.15.2 || ^8.0.1`,
  `symfony/finder ^7.2 || ^8`; PHPUnit 11–13.

### Breaking: write-protocol redesign

- Downloads no longer write to the final path under `LOCK_EX`. Instead:
  a per-entry **claim lock** (`.locks/{hash}.lock`) deduplicates concurrent
  downloads, the body streams into an exclusively locked temp file
  (`{hash}.{pid}.{random}.tmp`), and the entry is published with an atomic
  `rename()`. Readers can never observe a partially written file.
- The cache directory now also contains `.locks/`, `.lifecycle.lock`, and
  transient `*.tmp` files. `prune()` garbage-collects orphaned temp files
  (after a 60 s grace period) and idle claim files; `clear()` removes them.
- The lifecycle lock moved from the system temp directory into the cache
  directory. v4 and v5 workers must not share a cache directory: stop the
  v4 workers and delete the directory (or use a new `path`) before
  starting v5 workers.
- The downloaded payload is `fsync()`ed before the publishing `rename()`,
  so a power loss cannot leave a zero-length or truncated file under the
  published name.
- Zero-byte entries are valid and served as-is — except under a MIME
  whitelist, which always rejects empty files (deny-by-default).
- If a writer crashes mid-download, the kernel releases its locks and the
  next worker takes over; no manual intervention needed.
- Crashed writers' temp files are cleaned up by `prune()`; deletions verify
  device/inode identity between the locked descriptor and the path, so a
  concurrently re-published entry is never deleted by mistake.

### Breaking: lifecycle-lock semantics

- The lifecycle lock is reentrant within a process (nested `get()` inside
  `batch()` callbacks no longer self-deadlock, including across two manually
  constructed instances).
- `forget()` and `getOnce()`/`batchOnce()` cleanup now take the exclusive
  lifecycle lock, so they cannot race running batches. Inside a
  `batch()`/`batchOnce()` callback the deletion is **deferred**: the entry
  survives the whole callback and is deleted under a real exclusive lock
  right after the outermost batch releases its shared lock (`forget()`
  returns `true` = "deleted or scheduled"; eviction events/metrics fire at
  flush time). If a later chunk of the same batch re-downloads a forgotten
  entry, the flush removes the fresh copy too.
- Lifecycle-lock acquisition timeouts throw the new
  `LifecycleLockTimeoutException` (extends `RuntimeException`, so existing
  catch blocks keep working). `forget()` catches it, logs a warning and
  returns `false`; `batch()`/`batchOnce()`/`prune()`/`clear()` propagate it.
- `clear()` inside a `batch()`/`batchOnce()` callback throws `LogicException`
  immediately instead of hanging until the lock timeout.

### Breaking: configuration

- Invalid config values now throw `InvalidConfigurationException` instead of
  being silently coerced. Notably, a string value for `mime_types` used to
  silently disable the whitelist.
- Integer options are parsed exactly — also from env strings in exponent
  notation (`1e9`) — instead of through a float that rounded values above
  2^53 or overflowed; fractions and out-of-range values are rejected. Float
  options reject `NAN` and infinities.
- `path` is required and must be absolute (the Laravel config still defaults
  it to `storage/framework/cache/files`); standalone construction requires
  passing it explicitly.
- `timeout` default changed from `-1` (unlimited) to `300` seconds.
- `user_agent` default changed to `Laravel-FileStash/5.x`.
- `prune_interval => null` disables the scheduled prune.
- New options: `block_private_hosts` (SSRF hardening, default `false`) and
  `allowed_disks` (storage disks `disk://path` URLs may read; default
  `null` = all, `[]` = HTTP(S) only). Rejections throw
  `DiskNotAllowedException`, a subclass of `HostNotAllowedException`.
- `block_private_hosts` blocks the full set of special-purpose IPv4/IPv6
  ranges (explicit CIDR lists, not PHP's `filter_var` flags): CGNAT
  `100.64/10` (cloud metadata at `100.100.100.200`), benchmarking
  `198.18/15`, `192.0.0.0/24`, TEST-NETs, multicast, reserved, NAT64
  `64:ff9b::/96`, Teredo/`2001::/23`, 6to4 `2002::/16` (blanket-denied —
  the deprecated relay mechanism routes to embedded IPv4), and every IPv6
  address outside the global unicast space `2000::/3` (ULA, link/site
  local, IPv4-compatible `::/96`, SIIT, reserved space). v4-mapped IPv6 is
  unwrapped and checked by the IPv4 rules.
  Hostnames resolve via the union of `dns_get_record` (A + AAAA) and
  `gethostbynamel` (covers `/etc/hosts`); hosts that resolve to nothing are
  rejected (fail closed).
- IPv6 literals in `allowed_hosts` and URLs are canonicalized (brackets,
  case, zero compression), so `[2001:DB8::0001]` matches a whitelisted
  `2001:db8::1`.
- `allowed_hosts` input that parses to zero hosts (`','`, `[' ']`) throws
  `InvalidConfigurationException` instead of silently blocking all hosts;
  blocking everything requires an explicit `[]`.
- `mime_types` entries are lowercased, and reported MIME types are
  normalized (parameters like `; charset=` stripped, lowercased) before the
  whitelist check — S3 disks reporting `text/plain; charset=utf-8` and
  mixed-case whitelists now match. Undetectable types normalize to
  `(unknown)`, which never matches.
- Timeout options accept only `-1` (indefinite) or non-negative values;
  fractional negatives like `-0.5` used to silently disable the timeout.

### Breaking: HTTP layer

- `read_timeout` for HTTP(S) now maps to curl's low-speed abort (fails when
  the transfer stalls below 1 byte/s for that long). HTTP timeouts surface
  as Guzzle exceptions and participate in `http_retries`;
  `SourceResourceTimedOutException` is now only thrown for storage-disk
  streams.
- Responses stream directly to the temp file with an on-the-fly size limit —
  oversized downloads abort mid-transfer instead of buffering the whole body.
- HTTP retries restart from a truncated file (previously a retry after a
  partial write could append the second body after garbage).
- `exists()` with a configured MIME whitelist now denies responses without a
  `Content-Type` header (deny-by-default, matching disk behavior).
- `exists()` returns `false` only for definitive answers (4xx except 429,
  a redirect not followed to a document). 429 and 5xx responses throw
  `FailedToRetrieveFileException` (with `statusCode`) once `http_retries`
  are exhausted, like network errors already did — "the server is
  overloaded" no longer reads as "the file is gone".
- Redirect targets are validated against `allowed_hosts` and
  `block_private_hosts`.
- Redirect-hop bodies are discarded: they no longer pollute the cached file
  (Guzzle reuses the sink across hops without truncating) and no longer
  count against `max_file_size`.
- A non-2xx final response is always rejected — with `max_redirects => 0` a
  redirect answer used to be cached as a (usually empty) success.
- Security-critical request options (timeouts, `max_redirects`, the
  `on_redirect` host validation, the curl low-speed abort) are now applied
  **per request**, so an injected Guzzle client cannot silently disable
  them. The client's own `curl` options and redirect settings
  (`protocols`, `strict`, `referer`, `track_redirects`) are merged in,
  not dropped, and its own `on_redirect` callback runs after the host
  validation passed.
- Retry backoff applies jitter before the 30 s clamp, so the ceiling is a
  hard bound on the actual sleep (the sleep holds the entry claim and the
  shared lifecycle lock).
- Retry classification: a transfer that breaks after the response headers
  arrived (connection reset mid-body) is a network error and is retried;
  an exhausted redirect budget (`TooManyRedirectsException`) is not.
- Guzzle 8 is supported alongside Guzzle 7.

### Breaking: API surface

- The prune command was renamed to `file-stash:prune`; `prune-file-stash`
  remains as a deprecated alias until v6. It is registered lazily
  (`#[AsCommand]`); the `command.file-stash.prune` container binding is gone.
- The config is published with `--tag=file-stash-config` (the generic
  `config` tag still works) to `config_path()`.
- `CacheMiss` lost its `$url` property (use `$file->getUrl()`); all event
  classes are now `final readonly`.
- `FailedToRetrieveFileException` gained `public readonly int $statusCode`
  (`0` when the failure was not an HTTP error).
- `metrics()` was added to the `FileStash` contract.
- `app(FileStash::class)` now resolves to the same singleton as
  `app('file-stash')`.
- `FileStash::fake()` returns a rewritten `FileStashFake` that **extends
  `FileStash`** (concrete-class typehints keep working after the swap),
  creates real local files for callbacks, records calls, and provides
  `assertRetrieved()`, `assertRetrievedTimes()`, `assertNotRetrieved()`,
  `assertNothingRetrieved()`, `assertForgotten()`, `putFake()`, and
  `shouldExist()`. Like `Storage::fake()`, it works in a stable directory
  under `storage/framework/testing` (suffixed with the parallel-testing
  token) that is wiped on construction. It implements Laravel's `Fake`
  marker (`FileStash::isFake()`), `putFake()` also replaces the content of
  an already retrieved file, and `forget()`/`getOnce()` inside a batch
  callback are deferred until the batch returns, as in the real cache.

### Fixed

- `lock_wait_timeout => 0` no longer breaks reads of already-cached files
  (lock success was checked after the deadline).
- `prune()` returns honest `remaining`/`total_size` statistics when it stops
  early on `prune_timeout` (previously zeros), and no longer counts files
  that vanished on their own as deleted.
- Errors metric now increments on every failed retrieval, not only on lock
  exhaustion.
- A storage-disk source that dies mid-transfer is detected (EOF probe after
  the copy) and rejected instead of being cached truncated; failed
  `fflush()`/`fsync()` results abort the write instead of passing silently.
- `flock()` return values on the temp file (initial `LOCK_EX` and the
  EX→SH conversion) are checked; a lost conversion re-downloads under the
  same claim instead of returning an unprotected stream.
- Claim-lock retries (after a garbage-collected claim file) share one
  `lock_wait_timeout` budget instead of multiplying it by five.
- `FileLockedException` under `throwOnLock` no longer increments the errors
  metric (contention is expected control flow).
- `forget()` and `prune()` on a cold cache no longer create the cache
  directory as a side effect.
- `prune()` on a missing cache directory still dispatches
  `CachePruneCompleted`; temp/claim garbage collection no longer emits
  `CacheFileEvicted` events or bumps the eviction metric.
- `GenericFile` rejects URLs with an empty scheme or empty path
  (`'://x'`, `'disk://'`) at construction.
- `prune()`/`clear()` only touch files the cache created (entries and temp
  files directly inside the cache directory); they used to delete every
  file in the directory tree, including foreign files and subdirectories.
  `clear()` no longer reports orphaned temp files as evicted entries.
- New directories are created with `0777` minus the umask (were hard-coded
  `0755`), so a group-writable umask lets users share one cache; lock files
  another user created read-only are opened read-only instead of failing.
  An unopenable claim file now fails with a `RuntimeException` naming the
  path instead of burning `lock_max_attempts`.
- `prune()` no longer evicts entries a running chunked batch is using:
  chunked batches release their per-file locks before the callback and
  now hold a shared pin lock instead, which `prune()` must take
  exclusively for each eviction (it reports `completed => false` while a
  chunked batch runs).
- `prune()` re-checks an entry's access time under the exclusive lock
  before evicting it: an entry read after prune collected its statistics
  is kept instead of being evicted on stale data.
- A cache entry deleted outside the lock protocol (e.g. a script wiping
  the directory) right before a reader's access-time update is no longer
  recreated as an empty file and served as valid content; the reader
  re-downloads instead.
- The container-built instance logs through the application logger (its
  warnings were sent to a `NullLogger`), and events reach a dispatcher
  swapped in after the cache was resolved (`Event::fake()`).
- An invalid `prune_interval` cron expression is reported and the task
  skipped, instead of making `schedule:run` fail for every other task.
- `getOnce()`/`batchOnce()` no longer throw after the callback succeeded
  when the cleanup cannot acquire the lifecycle lock in time: a warning is
  logged, the result is returned, and the entry is left for `prune()`.
- `cache:clear` / `optimize:clear` no longer fail when the file stash's
  lifecycle lock is busy (a warning is logged instead), and a tag-scoped
  `cache:clear --tags=...` no longer wipes the file stash.
- A lock stream without `flock()` support (unsupported filesystem or
  stream wrapper) fails immediately instead of being treated as contended:
  with an indefinite timeout (`-1`) the lock loop used to spin forever.
  Indefinite waits now block in the kernel instead of polling.
- HTTP retry warnings no longer leak secrets into logs: query parameter
  values (presigned-URL signatures, tokens) and fragments are redacted in
  the logged URL and in the logged exception message, which Guzzle builds
  from the full request URI. Exceptions thrown to the caller are unchanged.
- Numerous smaller correctness fixes: dead code paths removed, per-URL path
  cache removed, `fflush()` before MIME checks and publishing, Windows
  rename retries.

### Internal

- New structure: `Support/` (`ConfigNormalizer`, `Url`, `HostValidator`,
  `IpRanges`, `MimeGuard`, `LockManager`, `BatchResult`, `CacheMetrics`,
  `DeleteResult`), `Http/` (`RemoteFetcher`, `SizeLimitedStream`).
- Multi-process concurrency test suite (`tests/Concurrency/`, `@group
  concurrency`): cold-start stampede, prune vs. batch, once-vs-get races,
  writer crash recovery, lifecycle-lock contention, redirect integrity
  through real curl, and deferred-deletion races.
- CI: PHP 8.2–8.5 × Laravel 12–13 matrix, prefer-lowest job, PHPStan level
  max, Laravel Pint.

## v4.x

See the git history.
