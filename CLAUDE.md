# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel package for fetching and caching files from HTTP(S) sources or Laravel storage disks, designed for concurrent processing with multiple parallel queue workers. Safety is built on `flock()`, atomic `rename()` publishing, and inode checks — the cache directory must live on a local POSIX filesystem (no NFS).

**Supported versions:** PHP ^8.3, Laravel ^12.61.1 || ^13.12, Guzzle ^7.15.2 || ^8.0.1 (floors = oldest releases without security advisories). Laravel 10/11 users stay on v4 (`^4.0`). Windows is best-effort (fast suite only, experimental CI job).

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

# Mutation testing (needs pcov; config in infection.json5, CI floor 97% MSI)
php -d pcov.enabled=1 vendor/bin/phpunit --exclude-group concurrency --coverage-xml=build/coverage/coverage-xml --log-junit=build/coverage/junit.xml
infection --coverage=build/coverage --skip-initial-tests --threads=max
```

## Architecture

### Core

- **`src/FileStash.php`** — orchestration: public API (`get`, `getOnce`, `batch`, `batchOnce`, `forget`, `prune`, `clear`, `exists`, `metrics`), the retrieve protocol, and pruning.
- **`src/Contracts/FileStash.php`** — public API contract. `src/Contracts/File.php` requires `getUrl()`: HTTP(S) URL or `diskname://path`. `src/GenericFile.php` is the default implementation.
- **`src/Support/`** — pure, unit-testable helpers:
  - `ConfigNormalizer` — strict validation (invalid values throw `InvalidConfigurationException`, no silent coercion), pure (no `config()`/`storage_path()` calls; the service provider passes Laravel config in).
  - `LockManager` — `flockWithTimeout()` (probes with `LOCK_NB`; only `$wouldBlock === 1` counts as contention — any other failure, e.g. a filesystem without flock, returns `false` at once; -1 then blocks in the kernel, other timeouts poll with jittered sleeps) and the reentrant lifecycle lock (static per-process registry `lockPath → LOCK_SH|LOCK_EX`; SH⊆SH/SH⊆EX/EX⊆EX nest, SH→EX upgrade always throws `LogicException`; `onOutermostRelease()` hooks run after the outermost frame drops its flocks — used for deferred deletions).
  - `Url` (encoding; `sanitizeForLogging()`/`redactUrls()` strip credentials and query values from logged URLs), `HostValidator` (allowed_hosts wildcards + `block_private_hosts`; DNS = union of `dns_get_record` A+AAAA and `gethostbynamel`, empty resolution fails closed), `IpRanges` (explicit blocked IPv4 CIDR list incl. CGNAT; IPv6 is allow-listed to global unicast `2000::/3`, then filtered by a deny list inside it (documentation, 6to4, Teredo, …); v4-mapped addresses are checked by the IPv4 rules, everything outside `2000::/3` (NAT64, ULA, link-local, …) is blocked; host canonicalization for IPv6 literals), `MimeGuard` (normalize: strip `;…`, lowercase, `(unknown)` fallback; `ensureAllowed` deny-by-default), `BatchResult`, `CacheMetrics`, `DeleteResult` (enum `Deleted|Skipped|Gone`).
- **`src/Http/`** — `RemoteFetcher` (Guzzle client, built lazily when none is injected; `requestOptions()` re-applies timeouts/max_redirects/on_redirect/curl low-speed **per request** so injected clients can't bypass them — Guzzle merges request options shallowly, so the client's own `curl` options and the `allow_redirects` keys `strict`/`referer`/`protocols`/`track_redirects` are merged in explicitly and the client's `on_redirect` runs after host validation; retries/backoff; HEAD-based `exists()`: 3xx/4xx except 429 → `false`, 429/5xx/network → `FailedToRetrieveFileException` after retries; post-transfer non-2xx rejection) and `SizeLimitedStream` (PSR-7 sink over a **borrowed** handle, the locked temp file — it never opens the path itself, because Windows locks are mandatory; aborts curl mid-transfer when `max_file_size` is exceeded; `reset(discardBody:)` truncates and is called per hop from on_headers — redirect bodies are discarded, not stored and not counted).
- **`src/Testing/FileStashFake.php`** — fake that **extends `FileStash`** (concrete typehints survive `FileStash::fake()`) and implements Laravel's `Fake` marker. It is hermetic — never downloads, never reads storage disks; `exists()` follows `putFake()`/`shouldExist()` — and mirrors the deferred deletions of the real cache inside batch callbacks. It creates real local files in a stable `storage/framework/testing` directory (wiped per construction, parallel-testing token suffix), records calls, and provides `assert*()` helpers.

### Write protocol (v5) — the core invariants

Cache layout inside `config['path']`: entry = `{sha256(url)}`; temp = `{sha256}.{pid}.{16hex}.tmp`; claim = `.locks/{sha256}.lock`; lifecycle lock = `.lifecycle.lock`; pin lock = `.pin.lock`. prune/clear only look at depth-0 files named like an entry or a temp file (`findCacheFiles()`); anything else in the directory is never touched. Temp files are garbage-collected by prune after a 60 s grace (`TEMP_GRACE_SECONDS`) and by clear (no eviction events for them).

1. **Read path** (`tryReadExisting`): `fopen('rb')` → `LOCK_SH` → `fstat`: `nlink == 0` → retry (entry was deleted/re-published); zero-byte entries are valid and served. `touchEntry()` rechecks `nlink` after `touch()`: an entry deleted outside the lock protocol makes touch() create an empty file under its name, which is removed (verify guard `size === 0`) before retrying. Hot reads never touch the claim.
2. **Write path** (`claimAndCreate` → `downloadAndPublish`): claim `LOCK_EX` (dedupes concurrent downloads; after acquiring, recheck `nlink == 0` against claim GC) → re-check `tryReadExisting` (winner may have published while we waited) → stream into temp file held under `LOCK_EX` its whole lifetime (flock returns checked; EX failure → fresh temp retry) → `fsync` (power-loss safety; no directory fsync — a lost rename is only a cache miss) → MIME check through the locked handle (`mime_content_type($stream)`) → atomic `rename(temp, entry)` → convert EX→SH **on the same fd** (follows the inode) → recheck `nlink` (the EX→SH conversion is not atomic on Linux; on loss, re-download under the same claim, max 2 attempts).
3. **Deletes**: core is `unlinkLocked(path, verify?)` — `LOCK_EX|LOCK_NB` on a fresh fd + optional `verify(fstat)` guard + compare `fstat($fd)` vs `stat($path)` dev/ino — never delete an entry that was concurrently re-published over the same path. `deleteEntry()` adds eviction metrics + `CacheFileEvicted`; temp/claim GC calls `unlinkLocked` directly (no events).
4. **Lock ordering** (no cycles): lifecycle → pin → per-file SH (batch holds many) → claim → temp.
5. Crash safety: the kernel drops flocks on process death; orphaned temps and idle claims are janitorial work for `prune()`.

### Lifecycle lock

`batch()`/`batchOnce()` and `prune()` hold it shared, `clear()` exclusive. Deletions never take it exclusively (that would wait for every running `get()` in the cache): chunked batches (`count > batch_chunk_size`) release per-file SH locks after each chunk and instead hold the **pin lock** (`.pin.lock`, SH) from before the first chunk through the callback; `prune()` takes the pin `LOCK_EX|LOCK_NB` around every single eviction (`evict()`) and stops evicting (`completed=false`) when it can't; `forget()`, the once-cleanup and the deferred flush go through `deleteUnusedEntries()` (pin EX bounded by `lifecycle_lock_timeout`, then `deleteEntry()`, which skips entries another worker holds). The lifecycle SH lock also tells deletions of this process that they are nested in a batch.

**Deferred deletions**: `forget()`/once-cleanup nested under this process's shared lifecycle lock may target an entry the callback still uses (and would block on this process's own pin SH); they queue the deletion (`deferredDeletions` per instance + `LockManager::onOutermostRelease` hook) and `flushDeferredDeletions()` runs it right after the outermost SH frame releases; the flush logs every failure (release hooks swallow exceptions). `forget()` returns `true` = "deleted or scheduled"; the entry stays alive for the whole callback. Eviction metrics/events fire at flush time. Lifecycle/pin acquisition timeouts throw `LifecycleLockTimeoutException` (extends `RuntimeException`); `forget()` catches it → warning → `false`, the once-cleanup logs it, `batch`/`batchOnce`/`prune`/`clear` propagate it.

### Service Provider & Facade

- **`src/FileStashServiceProvider.php`** — binds `file-stash` (aliased to the concrete class and the contract; gets the app logger, the event dispatcher is looked up per dispatch so `Event::fake()` works after resolution), registers the command lazily (`#[AsCommand]`), schedules `file-stash:prune` in `callAfterResolving(Schedule::class)` (disabled when `prune_interval` is null; an invalid cron is `report()`ed and skipped), listens to `cache:clearing`, publishes the config under the `file-stash-config` tag (and the generic `config`).
- **`src/Facades/FileStash.php`** — static access; `FileStash::fake()` returns `FileStashFake`.
- **`src/Console/Commands/PruneFileStash.php`** — `file-stash:prune` with deprecated alias `prune-file-stash`.

### Configuration

Config file: `src/config/file-stash.php`. Notable semantics:

- `allowed_hosts`: `null`/`''` = all allowed (default!), `[]` = all blocked, list = whitelist with `*.` wildcards (also matching the root domain); non-empty input parsing to zero hosts throws. `block_private_hosts` additionally rejects special-purpose IPs via `IpRanges` (no DNS-rebinding protection).
- `allowed_disks`: same `null`/`''`/`[]`/list semantics for the disk of `disk://path` URLs; checked in `getDisk()` → `DiskNotAllowedException extends HostNotAllowedException`.
- `read_timeout`: HTTP → curl low-speed abort (Guzzle exceptions, retried per `http_retries`); disk streams → `stream_set_timeout` (`SourceResourceTimedOutException`).
- `timeout` default 300 s; `max_file_size` -1 = unlimited; `batch_chunk_size` -1 = no chunking.
- Invalid values throw `InvalidConfigurationException`; `path` is required and absolute.

### Testing

- **`tests/TestCase.php`** — Orchestra Testbench base (package provider registered, per-test storage path). Pure unit tests (`tests/Support/`, `tests/Http/`) extend PHPUnit's `TestCase` directly.
- **`tests/FileStashTest.php`** — behavioral tests against a local HTTP mock (Guzzle MockHandler) and a `fixtures` disk (`tests/files/`). MockHandler ignores a short sink write, which makes curl abort; size-limit tests use `curlLikeHandler()`, which emulates that.
- **`tests/FileStashFunctionMockTest.php`** — php-mock for `fopen`/`flock`/`stream_copy_to_stream`/`usleep`/`random_int`. Requires `#[RunTestsInSeparateProcesses]`. php-mock is namespace-scoped: code in `src/Support/` needs mocks in the `Jackardios\FileStash\Support` namespace, `src/Http/` in `Jackardios\FileStash\Http`, `src/` in `Jackardios\FileStash`. A `flock` mock simulating contention must declare `&$wouldBlock = null` and set it to `1`; otherwise it simulates "flock unsupported" and `flockWithTimeout()` fails fast.
- **`tests/Support/`** — pure unit tests for `ConfigNormalizer`, `Url`, `HostValidator`, `IpRanges`, `MimeGuard`, `LockManager` (no Reflection); `HostValidatorDnsMockTest` uses php-mock for DNS resolution.
- **`tests/Concurrency/`** (`#[Group('concurrency')]`, skipped on Windows) — real multi-process tests: `ConcurrencyTestCase` spawns PHP workers via `proc_open` (`fixtures/worker.php`; the `nested` task key runs an op inside the batch callback) against a deterministic slow HTTP server (`fixtures/slow-server.php` under `php -S`; `redirect_to`/`redirect_status` query params stream a decoy body with a redirect; `no_length` omits Content-Length; each request logs `{counter}.sent` with the bytes actually sent). Covers cold-start stampede (exactly one download), prune-vs-batch races, getOnce-vs-get races, SIGKILL writer crash recovery, clear-vs-batch lifecycle contention, redirect integrity through real curl, streamed size-limit aborts, and deferred-delete races.
- Guzzle MockHandler quirks to remember: it invokes `on_headers` with queued *exceptions* too (keep the untyped param + `instanceof ResponseInterface` guard) and writes the sink only for fulfilled responses.
- Guzzle 8 changed `RequestException`'s constructor (the third parameter is `int $code`): build Guzzle exceptions in tests with named arguments (`message:`, `request:`, `previous:`).
- Never assert full PHP or Laravel error texts (they change between versions); assert the exception class and a stable fragment.
- `mockery/mockery` is not used directly, but Laravel's `PendingCommand` (behind `$this->artisan()` in command tests) requires it — do not remove it from require-dev.

### CI

`.github/workflows/tests.yml`: PHP 8.3–8.5 × Laravel 12/13 (+ Guzzle 8 on Laravel 13), paratest and sequential random order plus the concurrency suite; prefer-lowest on 8.3; PHPStan on the lowest and highest sets; Pint; `composer audit`; pcov coverage + Infection on PHP 8.4 (PECL has no pcov release for 8.5 yet); experimental Windows, PHP 8.6 (`--ignore-platform-req=php+`) and `laravel/framework:dev-master as 13.99.0` with `orchestra/testbench-core:12.x-dev as 11.99.0` (master's branch alias `13.0.x-dev` does not satisfy `^13.12`).
