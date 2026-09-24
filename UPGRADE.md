# Upgrade Guide

## Upgrading from v4 to v5

v5 rewrites the write protocol and requires newer PHP and Laravel versions.
Projects that have to stay on Laravel 10/11 or PHP 8.1/8.2 keep using v4:

```bash
composer require jackardios/laravel-file-stash:^4.0
```

The full list of changes is in the [CHANGELOG](CHANGELOG.md). The steps below
cover everything that needs action.

### 1. Check the requirements

| | v4 | v5 |
|---|---|---|
| PHP | `^8.1` | `^8.3` |
| Laravel | `^10 \|\| ^11 \|\| ^12` | `^12.61.1 \|\| ^13.12` |
| Guzzle | `^7.0` | `^7.15.2 \|\| ^8.0.1` |

Laravel 11 is not supported: its security-fix window closed before this
release, and every 11.x version is affected by known security advisories.

The floors are the oldest releases without known security advisories;
Composer's default `audit.block-insecure` setting refuses older ones anyway.

### 2. Update the package

```bash
composer require jackardios/laravel-file-stash:^5.0
```

### 3. Review your configuration

If you published `config/file-stash.php`, compare it with the package's
version (`vendor/jackardios/laravel-file-stash/src/config/file-stash.php`):

- New keys: `allowed_disks` and `block_private_hosts` (see step 6). Both
  default to the v4 behavior when absent.
- `timeout` now defaults to `300` seconds instead of `-1` (unlimited). Set
  `FILE_STASH_TIMEOUT=-1` to keep downloads unlimited.
- `user_agent` now defaults to `Laravel-FileStash/5.x`.

Invalid values are no longer coerced silently; they throw
`InvalidConfigurationException` when the cache is first resolved. Check your
configuration once after upgrading:

```bash
php artisan tinker --execute 'app("file-stash");'
```

Values that used to be accepted and now throw:

| Value | v4 | v5 |
|---|---|---|
| `mime_types` as a string | silently disabled the whitelist | throws; use an array |
| `allowed_hosts` that parses to no host (`','`, `[' ']`) | blocked every host | throws; use `[]` to block all hosts |
| A timeout between `-1` and `0` (e.g. `-0.5`) | meant "unlimited" | throws; use `-1` |
| A fractional value for an integer option (`max_age => 1.5`) | truncated | throws |
| `NAN` or `INF` for a timeout | accepted | throws |

### 4. Update your code

**Creating the cache yourself.** v4 merged `config('file-stash')` under the
array you passed; v5 uses only what you pass, and `path` is required. Pass
the package config explicitly:

```php
// v4
new FileStash([], $client);
new FileStash(['timeout' => 10], $client);

// v5
new FileStash(config('file-stash'), $client);
new FileStash([...config('file-stash'), 'timeout' => 10], $client);
```

Outside Laravel, pass an absolute `path` and, for `disk://` URLs, a
`FilesystemManager` to the constructor.

**Injected Guzzle clients.** Timeouts, `max_redirects`, the redirect host
check and the curl stall timeout are applied to every request and override
the client's own values. The client's other `curl` options and its redirect
settings (`protocols`, `strict`, `referer`, `track_redirects`) are kept, and
its own `on_redirect` callback runs after the host check.

**`exists()`** returns `false` only when the answer is definitive: a 4xx
other than 429, a final 3xx response, or too many redirects. A 429 or 5xx
response throws `FailedToRetrieveFileException` once `http_retries` are
exhausted, with the status in `$exception->statusCode`. Network errors
already threw in v4. If you used `false` to mean "try again later", catch
the exception instead.

**HTTP read timeouts** (`read_timeout`) surface as Guzzle exceptions and
count towards `http_retries`. `SourceResourceTimedOutException` is thrown
for storage-disk streams only.

**Events** are `final readonly` classes; do not extend them. `CacheMiss`
lost its `$url` property: use `$event->file->getUrl()`.

**Deletions and running batches.** `forget()` and the `getOnce()` /
`batchOnce()` cleanup no longer delete files that a running batch callback
may still use. A file another worker is reading is skipped (`forget()`
returns `false`, the cleanup leaves it to `prune()`). Inside a callback the
deletion happens after the outermost batch returns (`forget()` returns
`true` for "deleted or scheduled").
Calling `clear()` inside a batch callback throws `LogicException`.

**Lock timeouts** throw `LifecycleLockTimeoutException`, which extends
`RuntimeException`, so existing `catch (RuntimeException)` blocks still work.

**The prune command** is called `file-stash:prune`. `prune-file-stash`
remains a deprecated alias until v6. The package schedules the command
itself (`prune_interval`); if you scheduled the old name by hand, switch to
the new one or remove your entry. The `command.file-stash.prune` container
binding no longer exists.

**Container.** `app(FileStash::class)` and the contract resolve to the
`file-stash` singleton; v4 built a second instance for the class name.

**Tests.** `FileStash::fake()` returns a `FileStashFake` that creates real
local files, records calls and provides `assert*()` helpers. It never
downloads anything or reads storage disks; seed content with `putFake()`
and control `exists()` with `shouldExist()`.

### 5. Switch the workers over

v4 and v5 use different lock protocols and must not work on the same cache
directory at the same time: a v5 reader could serve a file that a v4 writer
has only just created, and v4's `clear()` and `prune()` do not see v5's
locks. Either:

- stop all v4 workers, delete the cache directory, and start the v5 workers:

  ```bash
  rm -rf storage/framework/cache/files   # or your configured path
  ```

  Delete the directory rather than running `clear()`: v4 may have left
  zero-length files behind, which v5 serves as valid empty entries.

- or point v5 at a new `path` and delete the old directory once the last v4
  worker is gone.

Besides the entries, the v5 cache directory holds `.locks/` (per-entry
claim files), `.lifecycle.lock`, `.pin.lock` and transient `*.tmp` files.
v4 kept its lifecycle lock in the system temp directory.

If web and queue workers run as different users, see
[Sharing the cache between users](README.md#sharing-the-cache-between-users):
v5 creates files with the process umask applied instead of fixed modes.

### 6. Optional: harden the sources

All three options default to the v4 behavior:

- `allowed_hosts`: the hosts HTTP(S) URLs may point to.
- `block_private_hosts`: reject private, loopback, link-local and other
  special-purpose addresses (SSRF protection).
- `allowed_disks`: the storage disks `disk://path` URLs may read. Set it
  when URLs come from user input; otherwise any configured disk is readable.
