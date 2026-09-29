<?php

/**
 * CLI worker for multi-process concurrency tests.
 *
 * Usage: php worker.php <base64-encoded JSON task>
 *
 * Task shape:
 * {
 *   "op": "get" | "getOnce" | "batch" | "prune" | "clear" | "forget",
 *   "urls": ["https://..."],
 *   "config": {"path": "/tmp/...", ...},
 *   "iterations": 1,
 *   "callback_sleep_ms": 0,
 *   "forget_in_callback": ["https://..."],
 *   "ready_file": "/path",   // touched once the worker has booted
 *   "wait_for": "/path",     // start only once this file exists
 *   "until_exists": "/path", // repeat the op until this file exists (instead of "iterations")
 *   "iteration_sleep_ms": 0  // pause between iterations (10 with "until_exists")
 * }
 *
 * "forget_in_callback" calls forget() inside the batch callback (after the
 * files are described) and records its return value plus whether the entry
 * still exists on disk right after the call. With it set, each batch
 * iteration's result becomes {"files": [...], "forgotten": [...]}.
 *
 * Prints a single JSON document to stdout:
 * {"ok": true, "results": [...]} or {"ok": false, "error": {"class": "...", "message": "..."}}
 */

use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\FileStashServiceProvider;
use Jackardios\FileStash\GenericFile;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';

// Diagnostics (e.g. deprecations of a PHP version in development) must never
// corrupt the JSON document on stdout.
ini_set('display_errors', 'stderr');

$task = json_decode(base64_decode($argv[1]), true);
if (! is_array($task)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => ['class' => 'InvalidArgumentException', 'message' => 'Invalid task JSON']]));
    exit(1);
}

// Private bootstrap cache: concurrent workers must not rewrite (and read
// half-replaced) the shared testbench manifest in vendor/.
$bootstrapCache = $task['bootstrap_cache'];
mkdir($bootstrapCache, 0777, true);
foreach (['APP_PACKAGES_CACHE' => 'packages.php', 'APP_SERVICES_CACHE' => 'services.php'] as $name => $file) {
    putenv("{$name}={$bootstrapCache}/{$file}");
}

Application::create(options: ['extra' => [
    'providers' => [FileStashServiceProvider::class],
    'dont-discover' => ['*'],
]]);

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
    $isBatchStyle = in_array($op, ['batch', 'prune', 'clear'], true);

    $forgetInCallback = $task['forget_in_callback'] ?? null;
    $batchCallback = static function ($files, $paths) use ($cache, $describeFile, $forgetInCallback, $task) {
        $described = array_map($describeFile, $paths);
        if ($forgetInCallback === null) {
            return $described;
        }

        $forgotten = array_map(static fn (string $url): array => [
            'forgotten' => $cache->forget(new GenericFile($url)),
            'exists_after' => file_exists($task['config']['path'].'/'.hash('sha256', $url)),
        ], $forgetInCallback);

        return ['files' => $described, 'forgotten' => $forgotten];
    };

    if (isset($task['ready_file'])) {
        touch($task['ready_file']);
    }

    $deadline = microtime(true) + 30;
    while (isset($task['wait_for']) && ! file_exists($task['wait_for']) && microtime(true) < $deadline) {
        usleep(5000);
    }

    $stopFile = $task['until_exists'] ?? null;
    $iterationSleepMs = (int) ($task['iteration_sleep_ms'] ?? ($stopFile === null ? 0 : 10));
    for ($i = 0; $stopFile === null ? $i < $iterations : ! file_exists($stopFile); $i++) {
        if ($i > 0 && $iterationSleepMs > 0) {
            usleep($iterationSleepMs * 1000);
        }

        if ($isBatchStyle) {
            $results[] = match ($op) {
                'batch' => $cache->batch(
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
