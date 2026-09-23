# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel package for fetching and caching files from HTTP(S) sources or Laravel storage disks, designed for concurrent processing with multiple parallel queue workers. Safety is built on `flock()`, atomic `rename()` publishing, and inode checks — the cache directory must live on a local POSIX filesystem (no NFS).

**Supported versions:** PHP ^8.3, Laravel ^12.61.1 || ^13.12

## Commands

```bash
# Run all tests (includes multi-process concurrency tests; POSIX only)
composer test

# Fast suite / concurrency suite separately
./vendor/bin/phpunit --exclude-group concurrency
./vendor/bin/phpunit --group concurrency

# Run a single test
./vendor/bin/phpunit --filter testGetRemote

# Static analysis (PHPStan level max)
composer analyse

# Code style (Laravel Pint; php_unit_method_casing disabled — tests stay camelCase)
./vendor/bin/pint --test
./vendor/bin/pint
```

## Architecture

### Core

- **`src/FileStash.php`** — orchestration: public API (`get`, `getOnce`, `batch`, `batchOnce`, `forget`, `prune`, `clear`, `exists`, `metrics`), the retrieve protocol, and pruning.
- **`src/Contracts/FileStash.php`** — public API contract. `src/Contracts/File.php` requires `getUrl()`: HTTP(S) URL or `diskname://path`. `src/GenericFile.php` is the default implementation.
- **`src/Support/`** — pure, unit-testable helpers:
  - `ConfigNormalizer` — strict validation (invalid values throw `InvalidConfigurationException`, no silent coercion), pure (no `config()`/`storage_path()` calls; the service provider passes Laravel config in).
  - `LockManager` — `flockWithTimeout()` (blocking flock for the -1 "indefinite" timeout, NB polling otherwise) and the reentrant lifecycle lock (static per-process registry `lockPath → LOCK_SH|LOCK_EX`; SH⊆SH/SH⊆EX/EX⊆EX nest, SH→EX upgrade always throws `LogicException`; `onOutermostRelease()` hooks run after the outermost frame drops its flocks — used for deferred deletions).
  - `Url`, `HostValidator` (allowed_hosts wildcards + `block_private_hosts`; DNS = union of `dns_get_record` A+AAAA and `gethostbynamel`, empty resolution fails closed), `IpRanges` (explicit blocked CIDR lists incl. CGNAT/NAT64/6to4, v4-mapped unwrap, host canonicalization for IPv6 literals), `MimeGuard` (normalize: strip `;…`, lowercase, `(unknown)` fallback; `ensureAllowed` deny-by-default), `BatchResult`, `CacheMetrics`, `DeleteResult` (enum `Deleted|Skipped|Gone`).
- **`src/Http/`** — `RemoteFetcher` (Guzzle client; `requestOptions()` re-applies timeouts/max_redirects/on_redirect/curl low-speed **per request** so injected clients can't bypass them; retries/backoff; HEAD-based `exists()`; post-transfer non-2xx rejection) and `SizeLimitedStream` (PSR-7 sink that aborts curl mid-transfer when `max_file_size` is exceeded; `reset(discardBody:)` is called per hop from on_headers — redirect bodies are discarded, not stored and not counted).
- **`src/Testing/FileStashFake.php`** — fake that **extends `FileStash`** (concrete typehints survive `FileStash::fake()`), creates real local files in a stable `storage/framework/testing` directory (wiped per construction, parallel-testing token suffix), records calls, and provides `assert*()` helpers.

### Write protocol (v5) — the core invariants

Cache layout inside `config['path']`: entry = `{sha256(url)}`; temp = `{sha256}.{pid}.{16hex}.tmp`; claim = `.locks/{sha256}.lock`; lifecycle lock = `.lifecycle.lock`. prune/clear only look at depth-0 files named like an entry or a temp file (`findCacheFiles()`); anything else in the directory is never touched. Temp files are garbage-collected by prune after a 60 s grace (`TEMP_GRACE_SECONDS`) and by clear (no eviction events for them).

1. **Read path** (`tryReadExisting`): `fopen('rb')` → `LOCK_SH` → `fstat`: `nlink == 0` → retry (entry was deleted/re-published); zero-byte entries are valid and served. Hot reads never touch the claim.
2. **Write path** (`claimAndCreate` → `downloadAndPublish`): claim `LOCK_EX` (dedupes concurrent downloads; after acquiring, recheck `nlink == 0` against claim GC) → re-check `tryReadExisting` (winner may have published while we waited) → stream into temp file held under `LOCK_EX` its whole lifetime (flock returns checked; EX failure → fresh temp retry) → `fsync` (power-loss safety; page cache is per-inode, covers the fetcher's own fd) → MIME check → atomic `rename(temp, entry)` → convert EX→SH **on the same fd** (follows the inode) → recheck `nlink` (the EX→SH conversion is not atomic on Linux; on loss, re-download under the same claim, max 2 attempts).
3. **Deletes**: core is `unlinkLocked(path, verify?)` — `LOCK_EX|LOCK_NB` on a fresh fd + optional `verify(fstat)` guard + compare `fstat($fd)` vs `stat($path)` dev/ino — never delete an entry that was concurrently re-published over the same path. `deleteEntry()` adds eviction metrics + `CacheFileEvicted`; temp/claim GC calls `unlinkLocked` directly (no events).
4. **Lock ordering** (no cycles): lifecycle → per-file SH (batch holds many) → claim → temp.
5. Crash safety: the kernel drops flocks on process death; orphaned temps and idle claims are janitorial work for `prune()`.

### Lifecycle lock

`batch()`/`batchOnce()` hold it shared; `forget()`, once-cleanup, `prune()` (shared), and `clear()` (exclusive) coordinate through it. Chunked batches (`count > batch_chunk_size`) release per-file SH locks before the callback — only the lifecycle lock protects the callback window then.

**Deferred deletions**: `forget()`/once-cleanup nested under a shared lifecycle lock cannot upgrade to EX; they queue the deletion (`deferredDeletions` per instance + `LockManager::onOutermostRelease` hook) and `flushDeferredDeletions()` runs it under a real EX lock right after the outermost SH frame releases. `forget()` returns `true` = "deleted or scheduled"; the entry stays alive for the whole callback. Eviction metrics/events fire at flush time. Acquisition timeouts throw `LifecycleLockTimeoutException` (extends `RuntimeException`); `forget()` catches it → warning → `false`, `batch`/`batchOnce`/`prune`/`clear` propagate it.

### Service Provider & Facade

- **`src/FileStashServiceProvider.php`** — binds `file-stash` (aliased to the concrete class and the contract), registers the scheduled `file-stash:prune` (disabled when `prune_interval` is null; console-only), listens to `cache:clearing`.
- **`src/Facades/FileStash.php`** — static access; `FileStash::fake()` returns `FileStashFake`.
- **`src/Console/Commands/PruneFileStash.php`** — `file-stash:prune` with deprecated alias `prune-file-stash`.

### Configuration

Config file: `src/config/file-stash.php`. Notable semantics:

- `allowed_hosts`: `null`/`''` = all allowed (default!), `[]` = all blocked, list = whitelist with `*.` wildcards (also matching the root domain); non-empty input parsing to zero hosts throws. `block_private_hosts` additionally rejects special-purpose IPs via `IpRanges` (no DNS-rebinding protection).
- `read_timeout`: HTTP → curl low-speed abort (Guzzle exceptions, retried per `http_retries`); disk streams → `stream_set_timeout` (`SourceResourceTimedOutException`).
- `timeout` default 300 s; `max_file_size` -1 = unlimited; `batch_chunk_size` -1 = no chunking.
- Invalid values throw `InvalidConfigurationException`; `path` is required and absolute.

### Testing

- **`tests/FileStashTest.php`** — behavioral tests against a local HTTP mock (Guzzle MockHandler) and a `fixtures` disk (`tests/files/`).
- **`tests/FileStashFunctionMockTest.php`** — php-mock for `fopen`/`flock`/`stream_copy_to_stream`. Requires `#[RunTestsInSeparateProcesses]`. php-mock is namespace-scoped: code in `src/Support/` needs mocks in the `Jackardios\FileStash\Support` namespace, code in `src/` in `Jackardios\FileStash`.
- **`tests/Support/`** — pure unit tests for `ConfigNormalizer`, `Url`, `HostValidator`, `IpRanges`, `MimeGuard`, `LockManager` (no Reflection); `HostValidatorDnsMockTest` uses php-mock for DNS resolution.
- **`tests/Concurrency/`** (`#[Group('concurrency')]`, skipped on Windows) — real multi-process tests: `ConcurrencyTestCase` spawns PHP workers via `proc_open` (`fixtures/worker.php`; the `nested` task key runs an op inside the batch callback) against a deterministic slow HTTP server (`fixtures/slow-server.php` under `php -S`; `redirect_to`/`redirect_status` query params stream a decoy body with a redirect). Covers cold-start stampede (exactly one download), prune-vs-batch races, getOnce-vs-get races, SIGKILL writer crash recovery, clear-vs-batch lifecycle contention, redirect integrity through real curl, and deferred-delete races.
- Guzzle MockHandler quirks to remember: it invokes `on_headers` with queued *exceptions* too (keep the untyped param + `instanceof ResponseInterface` guard) and writes the sink only for fulfilled responses.
- `mockery/mockery` is not used directly, but Laravel's `PendingCommand` (behind `$this->artisan()` in command tests) requires it — do not remove it from require-dev.
