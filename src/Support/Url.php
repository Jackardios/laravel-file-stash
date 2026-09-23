<?php

namespace Jackardios\FileStash\Support;

/**
 * Pure helpers for working with file URLs (`https://…` or `disk://path`).
 */
final class Url
{
    /**
     * Determine if a URL points to a remote file served over HTTP(S).
     */
    public static function isRemote(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return false;
        }

        $normalized = strtolower($scheme);

        return $normalized === 'http' || $normalized === 'https';
    }

    /**
     * Split a URL by the protocol separator.
     *
     * @return array{0: string, 1?: string}
     */
    public static function splitByProtocol(string $url): array
    {
        $parts = explode('://', $url, 2);

        if (isset($parts[1])) {
            return [$parts[0], $parts[1]];
        }

        return [$parts[0]];
    }

    /**
     * Escape special characters (e.g. spaces) that may occur in parts of a HTTP URL.
     *
     * Problematic characters are encoded while percent signs and + signs are
     * preserved, so already-encoded sequences pass through unchanged.
     */
    public static function encode(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return self::encodeUnsafeCharacters($url);
        }

        $encoded = strtolower($parts['scheme']).'://';

        if (isset($parts['user'])) {
            $encoded .= self::encodeUnsafeCharacters($parts['user']);
            if (isset($parts['pass'])) {
                $encoded .= ':'.self::encodeUnsafeCharacters($parts['pass']);
            }
            $encoded .= '@';
        }

        $host = $parts['host'];
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }
        $encoded .= $host;

        if (isset($parts['port'])) {
            $encoded .= ':'.$parts['port'];
        }

        $encoded .= self::encodeUnsafeCharacters($parts['path'] ?? '');

        if (isset($parts['query'])) {
            $encoded .= '?'.self::encodeUnsafeCharacters($parts['query']);
        }

        if (isset($parts['fragment'])) {
            $encoded .= '#'.self::encodeUnsafeCharacters($parts['fragment']);
        }

        return $encoded;
    }

    /**
     * Redact a URL for safe logging: userinfo and query parameter values
     * (presigned-URL signatures, tokens) become `***`, the fragment is
     * dropped. Parameter names stay readable for debugging.
     */
    public static function sanitizeForLogging(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $sanitized = $parts['scheme'].'://';
        if (isset($parts['user'])) {
            $sanitized .= '***';
            if (isset($parts['pass'])) {
                $sanitized .= ':***';
            }
            $sanitized .= '@';
        }
        $sanitized .= $parts['host'];
        if (isset($parts['port'])) {
            $sanitized .= ':'.$parts['port'];
        }
        $sanitized .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $sanitized .= '?'.implode('&', array_map(
                static fn (string $pair): string => str_contains($pair, '=') ? strstr($pair, '=', true).'=***' : '***',
                explode('&', $parts['query'])
            ));
        }

        return $sanitized;
    }

    /**
     * Apply sanitizeForLogging() to every URL inside a free-form text, such
     * as an HTTP client's exception message that repeats the request URI.
     */
    public static function redactUrls(string $text): string
    {
        // A URL ends at whitespace, quotes or angle brackets, and does not
        // swallow trailing sentence punctuation.
        return preg_replace_callback(
            '~[a-z][a-z0-9+.-]*://[^\s`\'"<>]*[^\s`\'"<>.,;:!?)]~i',
            static fn (array $m): string => self::sanitizeForLogging($m[0]),
            $text
        ) ?? $text;
    }

    /**
     * Encode unsafe URL characters in a single URL component.
     */
    private static function encodeUnsafeCharacters(string $value): string
    {
        return preg_replace_callback(
            '/[^A-Za-z0-9\-._~:@!$&\'()*+,;=%\/?#]/',
            static fn (array $m): string => rawurlencode($m[0]),
            $value
        ) ?? $value;
    }
}
