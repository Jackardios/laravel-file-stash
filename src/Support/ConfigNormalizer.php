<?php

namespace Jackardios\FileStash\Support;

use Jackardios\FileStash\Exceptions\InvalidConfigurationException;

/**
 * Normalize and validate file cache configuration.
 *
 * This is a pure function of its input: defaults live here, but nothing is
 * read from the Laravel container. The service provider passes the Laravel
 * config array in; standalone users pass their own. The `path` key has no
 * default — it is required and must be an absolute path.
 *
 * Invalid values throw an InvalidConfigurationException instead of being
 * silently coerced, so misconfiguration (e.g. a string `mime_types`) cannot
 * quietly disable a security feature.
 *
 * @phpstan-type NormalizedConfig array{
 *   max_file_size: int,
 *   max_age: int,
 *   max_size: int,
 *   lock_max_attempts: int,
 *   lock_wait_timeout: float,
 *   timeout: float,
 *   connect_timeout: float,
 *   read_timeout: float,
 *   prune_timeout: int,
 *   mime_types: array<int, string>,
 *   allowed_hosts: array<int, string>|null,
 *   allowed_disks: array<int, string>|null,
 *   block_private_hosts: bool,
 *   http_retries: int,
 *   http_retry_delay: int,
 *   lifecycle_lock_timeout: float,
 *   batch_chunk_size: int,
 *   path: string,
 *   user_agent: string,
 *   max_redirects: int,
 *   touch_interval: int,
 *   events_enabled: bool
 * }
 */
final class ConfigNormalizer
{
    private const DEFAULTS = [
        'max_file_size' => -1, // any size (-1 = unlimited)
        'max_age' => 60, // 1 hour in minutes
        'max_size' => 1_000_000_000, // 1 GB
        'lock_max_attempts' => 3, // 3 attempts
        'lock_wait_timeout' => -1.0, // indefinitely (-1 = no limit)
        'timeout' => 300.0, // 5 minutes for the whole transfer (-1 = no limit)
        'connect_timeout' => 30.0, // 30 seconds
        'read_timeout' => 30.0, // 30 seconds
        'prune_timeout' => 300, // 5 minutes
        'mime_types' => [],
        'allowed_hosts' => null, // null = all hosts allowed, [] = all hosts blocked
        'allowed_disks' => null, // null = all disks allowed, [] = all disks blocked
        'block_private_hosts' => false,
        'http_retries' => 0, // no retries by default
        'http_retry_delay' => 100, // 100ms base delay for retries (exponential backoff)
        'lifecycle_lock_timeout' => 30.0, // 30 seconds (-1 = indefinitely)
        'batch_chunk_size' => 100, // chunk size for batch operations
        'user_agent' => 'Laravel-FileStash/5.x',
        'max_redirects' => 5,
        'touch_interval' => 60, // seconds between touch() calls
        'events_enabled' => false,
    ];

    /**
     * @param  array<string, mixed>  $config
     * @return NormalizedConfig
     *
     * @throws InvalidConfigurationException
     */
    public static function normalize(array $config): array
    {
        $merged = [...self::DEFAULTS, 'path' => null, ...$config];

        $normalized = [
            'max_file_size' => self::toInt($merged['max_file_size'], 'max_file_size'),
            'max_age' => self::toInt($merged['max_age'], 'max_age'),
            'max_size' => self::toInt($merged['max_size'], 'max_size'),
            'lock_max_attempts' => self::toInt($merged['lock_max_attempts'], 'lock_max_attempts'),
            'lock_wait_timeout' => self::toFloat($merged['lock_wait_timeout'], 'lock_wait_timeout'),
            'timeout' => self::toFloat($merged['timeout'], 'timeout'),
            'connect_timeout' => self::toFloat($merged['connect_timeout'], 'connect_timeout'),
            'read_timeout' => self::toFloat($merged['read_timeout'], 'read_timeout'),
            'prune_timeout' => self::toInt($merged['prune_timeout'], 'prune_timeout'),
            'mime_types' => self::toMimeTypes($merged['mime_types']),
            'allowed_hosts' => self::toAllowedHosts($merged['allowed_hosts']),
            'allowed_disks' => self::toAllowedDisks($merged['allowed_disks']),
            'block_private_hosts' => self::toBool($merged['block_private_hosts'], 'block_private_hosts'),
            'http_retries' => self::toInt($merged['http_retries'], 'http_retries'),
            'http_retry_delay' => self::toInt($merged['http_retry_delay'], 'http_retry_delay'),
            'lifecycle_lock_timeout' => self::toFloat($merged['lifecycle_lock_timeout'], 'lifecycle_lock_timeout'),
            'batch_chunk_size' => self::toInt($merged['batch_chunk_size'], 'batch_chunk_size'),
            'user_agent' => self::toUserAgent($merged['user_agent']),
            'max_redirects' => self::toInt($merged['max_redirects'], 'max_redirects'),
            'touch_interval' => self::toInt($merged['touch_interval'], 'touch_interval'),
            'events_enabled' => self::toBool($merged['events_enabled'], 'events_enabled'),
            'path' => self::toPath($merged['path']),
        ];

        self::validate($normalized);

        return $normalized;
    }

    /**
     * @param  NormalizedConfig  $config
     *
     * @throws InvalidConfigurationException
     */
    private static function validate(array $config): void
    {
        if ($config['max_file_size'] < -1 || $config['max_file_size'] === 0) {
            throw InvalidConfigurationException::create('max_file_size', 'must be -1 (unlimited) or a positive number');
        }

        if ($config['max_age'] < 1) {
            throw InvalidConfigurationException::create('max_age', 'must be at least 1 minute');
        }

        if ($config['max_size'] < 0) {
            throw InvalidConfigurationException::create('max_size', 'must be 0 or a positive number');
        }

        if ($config['lock_max_attempts'] < 1) {
            throw InvalidConfigurationException::create('lock_max_attempts', 'must be at least 1');
        }

        self::validateTimeout($config['lock_wait_timeout'], 'lock_wait_timeout');
        self::validateTimeout($config['timeout'], 'timeout');
        self::validateTimeout($config['connect_timeout'], 'connect_timeout');
        self::validateTimeout($config['read_timeout'], 'read_timeout');

        if ($config['prune_timeout'] < -1) {
            throw InvalidConfigurationException::create('prune_timeout', 'must be -1 (no timeout) or a non-negative number');
        }

        if ($config['http_retries'] < 0) {
            throw InvalidConfigurationException::create('http_retries', 'must be 0 or a positive number');
        }

        if ($config['http_retry_delay'] < 0) {
            throw InvalidConfigurationException::create('http_retry_delay', 'must be 0 or a positive number');
        }

        self::validateTimeout($config['lifecycle_lock_timeout'], 'lifecycle_lock_timeout');

        if ($config['batch_chunk_size'] < -1 || $config['batch_chunk_size'] === 0) {
            throw InvalidConfigurationException::create('batch_chunk_size', 'must be -1 (no limit) or a positive number');
        }

        if ($config['max_redirects'] < 0) {
            throw InvalidConfigurationException::create('max_redirects', 'must be 0 or a positive number');
        }

        if ($config['touch_interval'] < 0) {
            throw InvalidConfigurationException::create('touch_interval', 'must be 0 or a positive number');
        }
    }

    /**
     * A timeout is either the -1 "indefinitely" sentinel or non-negative.
     * Rejecting the open interval (-1, 0) matters: values like -0.5 would
     * otherwise silently disable the timeout instead of throwing.
     *
     * @throws InvalidConfigurationException
     */
    private static function validateTimeout(float $value, string $key): void
    {
        if ($value !== -1.0 && $value < 0.0) {
            throw InvalidConfigurationException::create($key, 'must be -1 (indefinitely) or a non-negative number');
        }
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function toInt(mixed $value, string $key): int
    {
        if (is_int($value)) {
            return $value;
        }

        // (float) PHP_INT_MAX rounds up to 2^63, which no int can hold.
        if (is_float($value) && floor($value) === $value && abs($value) < (float) PHP_INT_MAX) {
            return (int) $value;
        }

        if (is_string($value)) {
            $int = self::parseIntegerString($value);
            if ($int !== null) {
                return $int;
            }
        }

        throw InvalidConfigurationException::create($key, 'must be an integer');
    }

    /**
     * Parse a decimal string ('100', ' -1 ', '1e9', '1.5E+3') that denotes an
     * integer, exactly: the digits are shifted by the exponent as text, so
     * nothing is rounded through a float. Fractions and values outside the
     * int range yield null.
     */
    private static function parseIntegerString(string $value): ?int
    {
        if (preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d{1,4}))?\s*$/', $value, $m) !== 1 || $m[2].($m[3] ?? '') === '') {
            return null;
        }

        $digits = $m[2].($m[3] ?? '');
        $point = strlen($m[2]) + (int) ($m[4] ?? 0);

        $whole = $point <= 0 ? '' : substr(str_pad($digits, $point, '0'), 0, $point);
        $fraction = $point <= 0 ? $digits : (string) substr($digits, $point);

        if (trim($fraction, '0') !== '') {
            return null;
        }

        $whole = ltrim($whole, '0');
        $int = filter_var(($m[1] === '-' ? '-' : '').($whole === '' ? '0' : $whole), FILTER_VALIDATE_INT);

        return is_int($int) ? $int : null;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function toFloat(mixed $value, string $key): float
    {
        $float = match (true) {
            is_float($value) => $value,
            is_int($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => null,
        };

        // NAN would pass every range check below; INF/overflow is no timeout.
        if ($float === null || ! is_finite($float)) {
            throw InvalidConfigurationException::create($key, 'must be a finite number');
        }

        return $float;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function toBool(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // Env vars arrive as strings; accept the usual "true"/"false"/"1"/"0"
        // spellings but reject anything ambiguous.
        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (is_bool($filtered)) {
            return $filtered;
        }

        throw InvalidConfigurationException::create($key, 'must be a boolean');
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function toUserAgent(mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidConfigurationException::create('user_agent', 'must be a string');
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidConfigurationException::create('user_agent', 'must not be empty');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidConfigurationException::create('user_agent', 'must not contain control characters');
        }

        return $value;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function toPath(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw InvalidConfigurationException::create('path', 'is required and must be a non-empty string');
        }

        $path = rtrim($value, '/\\');

        if (! self::isAbsolutePath($path)) {
            throw InvalidConfigurationException::create('path', 'must be an absolute path');
        }

        return $path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return true;
        }

        return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * @return array<int, string>
     *
     * @throws InvalidConfigurationException
     */
    private static function toMimeTypes(mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidConfigurationException::create('mime_types', 'must be an array of MIME type strings');
        }

        $types = [];
        foreach ($value as $type) {
            if (! is_string($type) || trim($type) === '') {
                throw InvalidConfigurationException::create('mime_types', 'must contain only non-empty MIME type strings');
            }

            // MimeGuard compares normalized (lowercase) types strictly.
            $types[] = strtolower(trim($type));
        }

        return $types;
    }

    /**
     * @return array<int, string>|null
     *
     * @throws InvalidConfigurationException
     */
    private static function toAllowedHosts(mixed $value): ?array
    {
        return self::toAllowList($value, 'allowed_hosts', 'hostnames', 'remote hosts', IpRanges::canonicalizeHost(...));
    }

    /**
     * @return array<int, string>|null
     *
     * @throws InvalidConfigurationException
     */
    private static function toAllowedDisks(mixed $value): ?array
    {
        return self::toAllowList($value, 'allowed_disks', 'disk names', 'storage disks', static fn (string $disk): string => $disk);
    }

    /**
     * Parse an allow list: null or '' = no restriction, [] = nothing
     * allowed, otherwise an array or a comma-separated string of names.
     *
     * @param  callable(string): string  $canonicalize
     * @return array<int, string>|null
     *
     * @throws InvalidConfigurationException
     */
    private static function toAllowList(mixed $value, string $key, string $names, string $subjects, callable $canonicalize): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            // An empty string (e.g. an unset env var) means "no restriction",
            // while an empty array means "all blocked".
            if (trim($value) === '') {
                return null;
            }

            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            throw InvalidConfigurationException::create($key, "must be null, a comma-separated string, or an array of {$names}");
        }

        $list = [];
        foreach ($value as $name) {
            if (! is_string($name)) {
                throw InvalidConfigurationException::create($key, "must contain only {$names} as strings");
            }

            $name = $canonicalize(trim($name));
            if ($name !== '') {
                $list[] = $name;
            }
        }

        // A non-empty input that parses to zero names (',', [' '], ...) is a
        // typo, not a request to block everything. Blocking all requires an
        // explicit empty array.
        if ($list === [] && $value !== []) {
            throw InvalidConfigurationException::create($key, "parsed to zero entries; use an explicit empty array to block all {$subjects}");
        }

        return $list;
    }
}
