<?php

/**
 * CLI worker for multi-process concurrency tests.
 *
 * Usage: php worker.php <base64-encoded JSON task>
 *
 * Task shape:
 * {
 *   "op": "get" | "getOnce" | "batch" | "batchOnce" | "prune" | "clear" | "forget" | "exists",
 *   "urls": ["https://..."],
 *   "config": {"path": "/tmp/...", ...},
 *   "disks": {"name": {"driver": "local", "root": "/tmp/..."}},
 *   "iterations": 1,
 *   "callback_sleep_ms": 0,
 *   "nested": {"op": "forget" | "get" | "getOnce", "urls": ["https://..."]}
 * }
 *
 * "nested" runs inside the batch/batchOnce callback (after the files are
 * described) and records each operation's return value plus whether the
 * entry still exists on disk right after the call. With "nested" set, each
 * batch iteration's result becomes {"files": [...], "nested": [...]}.
 *
 * Prints a single JSON document to stdout:
 * {"ok": true, "results": [...]} or {"ok": false, "error": {"class": "...", "message": "..."}}
 */

use Illuminate\Contracts\Console\Kernel;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\FileStashServiceProvider;
use Jackardios\FileStash\GenericFile;

require __DIR__.'/../../../vendor/autoload.php';

$task = json_decode(base64_decode($argv[1]), true);
if (! is_array($task)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => ['class' => 'InvalidArgumentException', 'message' => 'Invalid task JSON']]));
    exit(1);
}

$app = require __DIR__.'/../../../vendor/laravel/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->register(FileStashServiceProvider::class);

foreach (($task['disks'] ?? []) as $name => $diskConfig) {
    config(["filesystems.disks.{$name}" => $diskConfig]);
}

$op = $task['op'] ?? 'get';
$urls = $task['urls'] ?? [];
$iterations = max(1, (int) ($task['iterations'] ?? 1));
$callbackSleepMs = (int) ($task['callback_sleep_ms'] ?? 0);

$describeFile = static function (string $path) use ($callbackSleepMs): array {
    $info = [
        'path' => $path,
        'exists' => file_exists($path),
        'sha256' => is_readable($path) && is_file($path) ? hash_file('sha256', $path) : null,
        'size' => is_file($path) ? filesize($path) : null,
    ];

    if ($callbackSleepMs > 0) {
        usleep($callbackSleepMs * 1000);
    }

    return $info;
};

try {
    $cache = new FileStash($task['config'] ?? []);
    $results = [];
    $isBatchStyle = in_array($op, ['batch', 'batchOnce', 'prune', 'clear'], true);

    $nested = is_array($task['nested'] ?? null) ? $task['nested'] : null;
    $runNested = static function () use ($cache, $nested, $task): ?array {
        if ($nested === null) {
            return null;
        }

        $out = [];
        foreach (($nested['urls'] ?? []) as $url) {
            $file = new GenericFile($url);
            $entryPath = ($task['config']['path'] ?? '').'/'.hash('sha256', $url);

            $out[] = match ($nested['op'] ?? 'forget') {
                'forget' => [
                    'forgotten' => $cache->forget($file),
                    'exists_after' => file_exists($entryPath),
                ],
                'get' => [
                    'result' => $cache->get($file, fn ($f, $p) => hash_file('sha256', $p)),
                    'exists_after' => file_exists($entryPath),
                ],
                'getOnce' => [
                    'result' => $cache->getOnce($file, fn ($f, $p) => hash_file('sha256', $p)),
                    'exists_after' => file_exists($entryPath),
                ],
                default => throw new InvalidArgumentException('Unknown nested op'),
            };
        }

        return $out;
    };

    $batchCallback = static function ($files, $paths) use ($describeFile, $runNested) {
        $described = array_map($describeFile, $paths);
        $nestedResults = $runNested();

        return $nestedResults === null ? $described : ['files' => $described, 'nested' => $nestedResults];
    };

    for ($i = 0; $i < $iterations; $i++) {
        if ($isBatchStyle) {
            $results[] = match ($op) {
                'batch' => $cache->batch(
                    array_map(fn ($url) => new GenericFile($url), $urls),
                    $batchCallback
                ),
                'batchOnce' => $cache->batchOnce(
                    array_map(fn ($url) => new GenericFile($url), $urls),
                    $batchCallback
                ),
                'prune' => $cache->prune(),
                'clear' => (static function () use ($cache) {
                    $cache->clear();

                    return ['cleared' => true];
                })(),
            };

            continue;
        }

        foreach ($urls as $url) {
            $file = new GenericFile($url);

            $results[] = match ($op) {
                'get' => $cache->get($file, fn ($f, $path) => $describeFile($path)),
                'getOnce' => $cache->getOnce($file, fn ($f, $path) => $describeFile($path)),
                'forget' => ['forgotten' => $cache->forget($file)],
                'exists' => ['exists' => $cache->exists($file)],
                default => throw new InvalidArgumentException("Unknown op: {$op}"),
            };
        }
    }

    fwrite(STDOUT, json_encode(['ok' => true, 'results' => $results]));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'error' => ['class' => get_class($e), 'message' => $e->getMessage()],
    ]));
    exit(0);
}
