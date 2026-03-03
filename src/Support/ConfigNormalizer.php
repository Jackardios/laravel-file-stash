<?php

namespace Jackardios\FileStash\Support;

use Jackardios\FileStash\Exceptions\InvalidConfigurationException;

/**
 * Normalize and validate file cache configuration.
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
    /**
     * @param array<string, mixed> $config
     * @return NormalizedConfig
     *
     * @throws InvalidConfigurationException
     */
    public static function normalize(array $config): array
    {
        $merged = [
            'max_file_size' => -1, // any size (-1 = unlimited)
            'max_age' => 60, // 1 hour in minutes
            'max_size' => 1E+9, // 1 GB
            'lock_max_attempts' => 3, // 3 attempts
            'lock_wait_timeout' => -1, // indefinitely (-1 = no limit)
            'timeout' => -1, // indefinitely (-1 = no limit)
            'connect_timeout' => 30.0, // 30 seconds
            'read_timeout' => 30.0, // 30 seconds
            'prune_timeout' => 300, // 5 minutes
            'mime_types' => [],
            'allowed_hosts' => null, // null = all hosts allowed
            'http_retries' => 0, // no retries by default
            'http_retry_delay' => 100, // 100ms base delay for retries (exponential backoff)
            'lifecycle_lock_timeout' => 30.0, // 30 seconds (-1 = indefinitely)
            'batch_chunk_size' => 100, // chunk size for batch operations
            'user_agent' => 'Laravel-FileStash/4.x',
            'max_redirects' => 5,
            'touch_interval' => 60, // seconds between touch() calls
            'events_enabled' => false,
            'path' => self::defaultCachePath(),
            ...self::loadLaravelConfig(),
            ...$config,
        ];

        $normalized = [
            'max_file_size' => self::toInt($merged['max_file_size']),
            'max_age' => self::toInt($merged['max_age']),
            'max_size' => self::toInt($merged['max_size']),
            'lock_max_attempts' => self::toInt($merged['lock_max_attempts']),
            'lock_wait_timeout' => self::toFloat($merged['lock_wait_timeout']),
            'timeout' => self::toFloat($merged['timeout']),
            'connect_timeout' => self::toFloat($merged['connect_timeout']),
            'read_timeout' => self::toFloat($merged['read_timeout']),
            'prune_timeout' => self::toInt($merged['prune_timeout']),
            'mime_types' => self::toStringList($merged['mime_types']),
            'allowed_hosts' => self::normalizeAllowedHosts($merged['allowed_hosts']),
            'http_retries' => max(self::toInt($merged['http_retries']), 0),
            'http_retry_delay' => max(self::toInt($merged['http_retry_delay']), 0),
            'lifecycle_lock_timeout' => self::toFloat($merged['lifecycle_lock_timeout']),
            'batch_chunk_size' => self::toInt($merged['batch_chunk_size']),
            'user_agent' => self::toString($merged['user_agent']),
            'max_redirects' => max(self::toInt($merged['max_redirects']), 0),
            'touch_interval' => max(self::toInt($merged['touch_interval']), 0),
            'events_enabled' => (bool) $merged['events_enabled'],
            'path' => self::toString($merged['path']),
        ];

        self::validate($normalized);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadLaravelConfig(): array
    {
        try {
            $config = config('file-stash', []);
            if (!is_array($config)) {
                return [];
            }

            $result = [];
            foreach ($config as $key => $value) {
                if (is_string($key)) {
                    $result[$key] = $value;
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function defaultCachePath(): string
    {
        try {
            return storage_path('framework/cache/files');
        } catch (\Throwable) {
            return sys_get_temp_dir() . '/laravel-file-stash';
        }
    }

    /**
     * @param NormalizedConfig $config
     *
     * @throws InvalidConfigurationException
     */
    private static function validate(array $config): void
    {
        if ($config['max_file_size'] < -1) {
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

        if ($config['lock_wait_timeout'] < -1) {
            throw InvalidConfigurationException::create('lock_wait_timeout', 'must be -1 (indefinitely) or a non-negative number');
        }

        if ($config['timeout'] < -1) {
            throw InvalidConfigurationException::create('timeout', 'must be -1 (indefinitely) or a non-negative number');
        }

        if ($config['connect_timeout'] < -1) {
            throw InvalidConfigurationException::create('connect_timeout', 'must be -1 (indefinitely) or a non-negative number');
        }

        if ($config['read_timeout'] < -1) {
            throw InvalidConfigurationException::create('read_timeout', 'must be -1 (indefinitely) or a non-negative number');
        }

        if ($config['prune_timeout'] < -1) {
            throw InvalidConfigurationException::create('prune_timeout', 'must be -1 (no timeout) or a non-negative number');
        }

        if ($config['lifecycle_lock_timeout'] < -1) {
            throw InvalidConfigurationException::create('lifecycle_lock_timeout', 'must be -1 (indefinitely) or a non-negative number');
        }

        if ($config['batch_chunk_size'] < -1 || $config['batch_chunk_size'] === 0) {
            throw InvalidConfigurationException::create('batch_chunk_size', 'must be -1 (no limit) or a positive number');
        }
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return 0;
    }

    private static function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return 0.0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return array<int, string>|null
     */
    private static function normalizeAllowedHosts(mixed $allowedHosts): ?array
    {
        if ($allowedHosts === null) {
            return null;
        }

        if (is_string($allowedHosts)) {
            if ($allowedHosts === '') {
                return null;
            }

            return array_values(array_map('trim', explode(',', $allowedHosts)));
        }

        if (is_array($allowedHosts)) {
            return self::toStringList($allowedHosts);
        }

        return [trim(self::toString($allowedHosts))];
    }

    /**
     * @return array<int, string>
     */
    private static function toStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn(mixed $item): string => trim(self::toString($item)), $value));
    }
}
